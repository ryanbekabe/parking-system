<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\ParkingGateRequest;
use App\ParkingGate;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\Printer;
use PhpSerial\PhpSerial;
use GuzzleHttp\Client;

class ParkingGateController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:1')->except(['getList', 'search', 'openGate', 'takeSnapshot']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $sort = $request->sort ? $request->sort : 'name';
        $order = $request->order == 'ascending' ? 'asc' : 'desc';

        return ParkingGate::when($request->keyword, function ($q) use ($request) {
                return $q->where('name', 'LIKE', '%' . $request->keyword . '%')
                    ->orWhere('controller_ip_address', 'LIKE', '%' . $request->keyword . '%');
            })->when($request->vehicle_type, function ($q) use ($request) {
                return $q->whereIn('vehicle_type', $request->vehicle_type);
            })->orderBy($sort, $order)->paginate($request->pageSize);
        }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(ParkingGateRequest $request)
    {
        $gate = ParkingGate::create($request->all());
        shell_exec('sudo systemctl restart parking');
        return $gate;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        $gate = ParkingGate::when($request->type, function($q) use ($request) {
                return $q->where('type', $request->type);
            })->when($request->controller_ip_address, function($q) use ($request) {
                return $q->where('controller_ip_address', $request->controller_ip_address);
            })->where('active', 1)->get();

        if (!$gate) {
            return response(['message' => 'Not found'], 404);
        }

        return $gate;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(ParkingGateRequest $request, ParkingGate $parkingGate)
    {
        $parkingGate->update($request->all());

        // restart service hanya kalau ada yg berubah & gate in aja
        if (!!$parkingGate->getChanges() && $request->type == 'IN') {
            shell_exec('sudo systemctl restart parking');
        }

        return $parkingGate;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(ParkingGate $parkingGate)
    {
        $parkingGate->delete();
        shell_exec('sudo systemctl restart parking');
        return ['message' => 'Parking gate telah dihapus'];
    }

    public function getList()
    {
        return ParkingGate::orderBy('name', 'asc')->where('active', 1)->get();
    }

    public function testCamera(ParkingGate $parkingGate)
    {
        $client = new Client(['timeout' => 3]);

        try {
            $response = $client->request('GET', $parkingGate->camera_image_snapshot_url, [
                'auth' => [
                    $parkingGate->camera_username,
                    $parkingGate->camera_password,
                    $parkingGate->camera_auth_type == 'digest' ? 'digest' : null
                ]
            ]);

            if ($response->getHeader('Content-Type')[0] != 'image/jpeg') {
                return response(['message' => 'GAGAL MENGAMBIL GAMBAR. URL SNAPSHOT KAMERA TIDAK SESUAI'], 500);
            }

        } catch (\Exception $e) {
            return response(['message' => 'GAGAL MENGAMBIL GAMBAR. '. $e->getMessage()], 500);
        }

        return [
            'message' => 'BERHASIL MENGAMBIL SNAPSHOT',
            'snapshot' => base64_encode($response->getBody()->getContents())
        ];
    }

    public function testPrinter(ParkingGate $parkingGate)
    {
        if ($parkingGate->type == 'IN' && $parkingGate->printer_type == 'local') {
            return response(['message' => 'PRINTER GATE IN SERIAL TIDAK BISA DITEST DARI WEB'], 500);
        }

        try {
            if ($parkingGate->printer_type == "network") {
                $connector = new NetworkPrintConnector($parkingGate->printer_ip_address, 9100);
            } else if ($parkingGate->printer_type == "local") {
                $connector = new FilePrintConnector($parkingGate->printer_device);
            } else {
                return response(['message' => 'INVALID PRINTER'], 500);
            }

            $printer = new Printer($connector);
        } catch (\Exception $e) {
            return response(['message' => 'KONEKSI KE PRINTER GAGAL. ' . $e->getMessage()], 500);
        }

        try {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("TEST PRINTER\n");
            $printer->text($parkingGate->name . "\n");
            $printer->text(date('d-M-Y H:i:s'));
            $printer->text("\n\n");
            $printer->setBarcodeHeight(100);
            $printer->setBarcodeWidth(4);
            $printer->setBarcodeTextPosition(Printer::BARCODE_TEXT_BELOW);
            $printer->barcode("ABC123");
            $printer->cut();
            $printer->close();
        } catch (\Exception $e) {
            return response(['message' => 'GAGAL MENCETAK.' . $e->getMessage()], 500);
        }

        return ['message' => 'BERHASIL MENCETAK'];
    }

    public function openGate(ParkingGate $parkingGate)
    {
        if ($parkingGate->type == 'IN') {
            return response(['message' => 'Gate IN tidak bisa ditest dari browser'], 500);
        }

        // kalau controller_ip_address kosong berarti interface langsung nancep
        if (!$parkingGate->controller_ip_address)
        {
            try {
                $serial = new PhpSerial;
                $serial->deviceSet($parkingGate->controller_device);
                $serial->confBaudRate($parkingGate->controller_baudrate);
                $serial->confParity("none");
                $serial->confCharacterLength(8);
                $serial->confStopBits(1);
                $serial->confFlowControl("none");
                $serial->deviceOpen();
                // ada 2 jenis: A, B, C, D (tergantung relay nomor berapa) atau AZ123, atau *TRIG1#, *OPEN1#
                $serial->sendMessage($parkingGate->cmd_open);

                if ($parkingGate->cmd_close) {
                    sleep(1);
                    // W,X,Y,Z (tergantung relay nomor berapa)
                    $serial->sendMessage($parkingGate->cmd_close);
                }

                $serial->deviceClose();
            } catch (\Exception $e) {
                return response(['message' => 'GAGAL MEMBUKA GATE. '. $e->getMessage()], 500);
            }

            return ['message' => 'GATE BERHASIL DIBUKA'];

        } else {
            return response(['message' => 'Controller tidak terkoneksi langsung dengan server'], 500);
        }


        // else
        // {
        //     $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        //     if (!is_resource($socket)) {
        //         return response(['message' => 'GAGAL MEMBUKA GATE. Failed to create socket.'], 500);
        //     }

        //     if (!socket_connect($socket, $parkingGate->controller_ip_address, $parkingGate->controller_port)) {
        //         return response(['message' => 'GAGAL MEMBUKA GATE. Socket connection failed.'], 500);
        //     }

        //     $command = "OPEN";
        //     $length = strlen($command);

        //     if (!socket_write($socket, $command, $length)) {
        //         return response(['message' => 'GAGAL MEMBUKA GATE. Failed to send command.'], 500);
        //     }

        //     $response = socket_read($socket, 1024);

        //     if (!$response) {
        //         return response(['message' => 'GAGAL MEMBUKA GATE. Empty respons.'], 500);
        //     } elseif ($response != 'OK') {
        //         return response(['message' => $response], 500);
        //     }

        //     socket_shutdown($socket);
        //     socket_close($socket);
        // }
    }

    public function takeSnapshot(ParkingGate $parkingGate)
    {
        if (!$parkingGate->camera_status || !$parkingGate->camera_image_snapshot_url) {
            return response(['message' => 'GAGAL MENGAMBIL GAMBAR. TIDAK ADA KAMERA.'], 404);
        }

        $client = new Client(['timeout' => 3]);
        $fileName = 'snapshot/'.date('Y/m/d/H/').$parkingGate->name.'-'.date('YmdHis').'.jpg';

        if (!is_dir('snapshot/'.date('Y/m/d/H'))) {
            mkdir('snapshot/'.date('Y/m/d/H'), 0777, true);
        }

        try {
            $response = $client->request('GET', $parkingGate->camera_image_snapshot_url, [
                'auth' => [
                    $parkingGate->camera_username,
                    $parkingGate->camera_password,
                    $parkingGate->camera_auth_type == 'digest' ? 'digest' : null
                ]
            ]);

            if ($response->getHeader('Content-Type')[0] == 'image/jpeg') {
                file_put_contents($fileName, $response->getBody());
            } else {
                return response(['message' => 'GAGAL MENGAMBIL GAMBAR. URL SNAPSHOT KAMERA TIDAK SESUAI'], 500);
            }
        } catch (\Exception $e) {
            return response(['message' => 'GAGAL MENGAMBIL GAMBAR. '. $e->getMessage()], 500);
        }

        return ['filename' => $fileName];
    }
}
