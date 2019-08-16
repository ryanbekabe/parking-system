<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\ParkingTransaction;
use App\Http\Requests\ParkingTransactionRequest;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\Printer;
use PhpSerial\PhpSerial;
use App\LocationIdentity;
use App\ParkingGate;
use GuzzleHttp\Client;

class ParkingTransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $sort = $request->sort ? $request->sort : 'updated_at';
        $order = $request->order == 'ascending' ? 'asc' : 'desc';

        return ParkingTransaction::selectRaw('
                parking_transactions.*,
                users.name AS operator,
                parking_members.name as member,
                parking_gate_in.name as gate_in,
                parking_gate_out.name as gate_out
            ')
            ->join('users', 'users.id', '=', 'parking_transactions.user_id', 'LEFT')
            ->join('parking_members', 'parking_members.id', '=', 'parking_transactions.parking_member_id', 'LEFT')
            ->join('parking_gates AS parking_gate_in', 'parking_gate_in.id', '=', 'parking_transactions.gate_in_id', 'LEFT')
            ->join('parking_gates AS parking_gate_out', 'parking_gate_out.id', '=', 'parking_transactions.gate_out_id', 'LEFT')
            ->when($request->dateRange, function($q) use ($request) {
                return $q->whereBetween('parking_transactions.updated_at', $request->dateRange);
            })->when($request->keyword, function ($q) use ($request) {
                return $q->where('barcode_number', 'LIKE', '%' . $request->keyword . '%')
                    ->orWhere('parking_transactions.plate_number', 'LIKE', '%' . $request->keyword . '%')
                    ->orWhere('parking_transactions.card_number', 'LIKE', '%' . $request->keyword . '%');
            })->when($request->is_member, function ($q) use ($request) {
                return $q->whereIn('is_member', $request->is_member);
            })->orderBy($sort, $order)->paginate($request->pageSize);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $input = $request->all();

        if (auth()->check()) {
            $input['operator'] = auth()->user()->name;
        }

        return ParkingTransaction::create($input);
    }

    public function takeSnapshot(Request $request, ParkingTransaction $parkingTransaction)
    {
        $gate = ParkingGate::find($request->gate_out_id);

        if (!$gate || !$gate->camera_status || !$gate->camera_image_snapshot_url) {
            return response(['message' => 'GAGAL MENGAMBIL GAMBAR. TIDAK ADA KAMERA.'], 404);
        }

        $client = new Client(['timeout' => 3]);
        $fileName = 'snapshot/'.date('YmdHis').'.jpg';

        try {
            $response = $client->request('GET', $gate->camera_image_snapshot_url, [
                'auth' => [
                    $gate->camera_username,
                    $gate->camera_password,
                    $gate->camera_auth_type == 'digest' ? 'digest' : null
                ]
            ]);
            file_put_contents($fileName, $response->getBody());
        } catch (\Exception $e) {
            return response(['message' => 'GAGAL MENGAMBIL GAMBAR. '. $e->getMessage()], 500);
        }

        $parkingTransaction->update(['snapshot_out' => $fileName]);
        return $parkingTransaction;
    }

    public function printTicket(ParkingTransaction $parkingTransaction)
    {
        try {
            $printerDevice = env("PRINTER_DEVICE", "/dev/ttyS0");
            $printerType = env("PRINTER_TYPE", "serial");

            if ($printerType == "network") {
                $connector = new NetworkPrintConnector($printerDevice, env("PRINTER_PORT", 9100));
            } else if ($printerType == "serial") {
                $connector = new FilePrintConnector($printerDevice);
            } else {
                return response(['message' => 'INVALID PRINTER'], 500);
            }

            $printer = new Printer($connector);
        } catch (\Exception $e) {
            return response(['message' => 'GAGAL MENCETAK STRUK.' . $e->getMessage()], 500);
        }

        $location = LocationIdentity::where('active', 1)->first();

        if (!$location) {
            return response(['message' => 'LOKASI TIDAK DISET'], 500);
        }

        try {
            $gateOut = ParkingGate::find($parkingTransaction->gate_out_id);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("STRUK PARKIR\n");
            $printer->text($location->name . "\n");
            $printer->text($location->address . "\n\n");

            $printer->text('Rp. ' . number_format($parkingTransaction->fare, 0, ',', '.') . ",-\n");
            $printer->text($parkingTransaction->plate_number . "/". $parkingTransaction->vehicle_type . "/" . $gateOut->name);
            $printer->text("\n\n");

            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_pad('WAKTU MASUK', 15, ' ') . ' : ' . $parkingTransaction->time_in . "\n");
            $printer->text(str_pad('WAKTU KELUAR', 15, ' ') . ' : ' . $parkingTransaction->time_out . "\n");
            $printer->text(str_pad('DURASI', 15, ' ') . ' : ' . $parkingTransaction->durasi . "\n");
            $printer->text(str_pad('PETUGAS', 15, ' ') . ' : ' . strtoupper(auth()->user()->name) . "\n\n");

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("TERIMAKASIH ATAS KUNJUNGAN ANDA\n");

            $printer->cut();
            $printer->close();
        } catch (\Exeption $e) {
            return response(['message' => 'GAGAL MENCETAK STRUK.' . $e->getMessage()], 500);
        }

        return ['message' => 'SILAKAN AMBIL STRUK'];
    }

    public function openGate()
    {
        try {
            $serial = new PhpSerial;
            $serial->deviceSet(env('GATE_OUT_CONTROLLER_DEVICE', '/dev/ttyS4'));
            $serial->confBaudRate(2400);
            $serial->confParity("none");
            $serial->confCharacterLength(8);
            $serial->confStopBits(1);
            $serial->confFlowControl("none");
            $serial->deviceOpen();
            $serial->sendMessage("AZ123");
            $serial->deviceClose();
        } catch (\Exception $e) {
            return response(['message' => 'GAGAL MEMBUKA GATE. '. $e->getMessage()], 500);
        }

        return ['message' => 'GATE BERHASIL DIBUKA'];
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        $data = ParkingTransaction::where('barcode_number', $request->barcode_number)
            ->where('time_out', null)
            ->first();

        if ($data) {
            return $data;
        }

        return response(['message' => 'Data tidak ditemukan'], 404);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(ParkingTransactionRequest $request, ParkingTransaction $parkingTransaction)
    {
        $input = $request->all();

        if (auth()->check()) {
            $input['user_id'] = auth()->user()->id;
            $input['operator'] = auth()->user()->name;
        }

        $parkingTransaction->update($input);
        return $parkingTransaction;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(ParkingTransaction $parkingTransaction)
    {
        $parkingTransaction->delete();
        return ['message' => 'Transaksi telah dihapus'];
    }

    public function setSudahKeluar(ParkingTransaction $parkingTransaction)
    {
        $parkingTransaction->time_out = now();
        $parkingTransaction->save();
        return ['message' => 'KENDARAAN BERHASIL DISET SUDAH KELUAR'];
    }
}
