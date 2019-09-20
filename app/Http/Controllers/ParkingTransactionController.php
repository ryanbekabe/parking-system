<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\ParkingTransaction;
use App\Http\Requests\ParkingTransactionRequest;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\Printer;
use App\LocationIdentity;
use App\ParkingGate;
use App\ParkingMember;
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
                parking_members.name as member,
                parking_gate_in.name as gate_in,
                parking_gate_out.name as gate_out
            ')
            ->join('parking_members', 'parking_members.id', '=', 'parking_transactions.parking_member_id', 'LEFT')
            ->join('parking_gates AS parking_gate_in', 'parking_gate_in.id', '=', 'parking_transactions.gate_in_id', 'LEFT')
            ->join('parking_gates AS parking_gate_out', 'parking_gate_out.id', '=', 'parking_transactions.gate_out_id', 'LEFT')
            ->when($request->dateRange, function($q) use ($request) {
                return $q->whereRaw('DATE(parking_transactions.updated_at) BETWEEN "'.$request->dateRange[0].'" AND "'.$request->dateRange[1].'"');
            })->when($request->keyword, function ($q) use ($request) {
                return $q->where('parking_transactions.barcode_number', 'LIKE', '%' . $request->keyword . '%')
                    ->orWhere('parking_transactions.plate_number', 'LIKE', '%' . $request->keyword . '%')
                    ->orWhere('parking_transactions.card_number', 'LIKE', '%' . $request->keyword . '%')
                    ->orWhere('parking_transactions.operator', 'LIKE', '%' . $request->keyword . '%');
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

        if ($request->is_member) {
            ParkingMember::where('id', $request->parking_member_id)->update([
                'last_transaction' => now()
            ]);
        }

        return ParkingTransaction::create($input);
    }

    public function takeSnapshot(Request $request, ParkingTransaction $parkingTransaction)
    {
        $gateId = $request->gate_in_id ? $request->gate_in_id : $request->gate_out_id;
        $gate = ParkingGate::find($gateId);

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

        $data = $request->gate_in_id ? ['snapshot_in' => $fileName] : ['snapshot_out' => $fileName];
        $parkingTransaction->update($data);
        return $parkingTransaction;
    }

    public function printTicket(Request $request, ParkingTransaction $parkingTransaction)
    {
        $location = LocationIdentity::where('active', 1)->first();

        if (!$location) {
            return response(['message' => 'LOKASI TIDAK DISET'], 500);
        }

        $gateId = $request->trx == 'OUT' ? $parkingTransaction->gate_out_id : $parkingTransaction->gate_in_id;
        $gate = ParkingGate::find($gateId);

        try {
            if ($gate->printer_type == "network") {
                $connector = new NetworkPrintConnector($gate->printer_ip_address, 9100);
            } else if ($gate->printer_type == "local") {
                $connector = new FilePrintConnector($gate->printer_device);
            } else {
                return response(['message' => 'INVALID PRINTER'], 500);
            }

            $printer = new Printer($connector);
        } catch (\Exception $e) {
            return response(['message' => 'KONEKSI KE PRINTER GAGAL. ' . $e->getMessage()], 500);
        }

        if ($request->trx == 'OUT')
        {
            try {
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                $printer->text("STRUK PARKIR\n");
                $printer->text($location->name . "\n");
                $printer->text($location->address . "\n\n");

                $printer->text('Rp. ' . number_format($parkingTransaction->fare, 0, ',', '.') . ",-\n");
                $printer->text($parkingTransaction->plate_number . "/". $parkingTransaction->vehicle_type . "/" . $gate->name);
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
        }

        if ($request->trx == 'IN')
        {
            try {
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                $printer->text("TIKET PARKIR\n");
                $printer->text($location->name . "\n");
                $printer->text($location->address . "\n\n");

                $printer->text('Rp. ' . number_format($parkingTransaction->fare, 0, ',', '.') . ",-\n");
                $printer->text($parkingTransaction->plate_number . "/". $parkingTransaction->vehicle_type);
                $printer->text("\n\n");

                $printer->setJustification(Printer::JUSTIFY_LEFT);
                $printer->text(str_pad('GATE', 15, ' ') . ' : ' . $gate->name . "\n");
                $printer->text(str_pad('WAKTU MASUK', 15, ' ') . ' : ' . $parkingTransaction->time_in . "\n");
                $printer->text(str_pad('PETUGAS', 15, ' ') . ' : ' . strtoupper(auth()->user()->name) . "\n\n");
                $printer->setJustification(Printer::JUSTIFY_CENTER);
                // $printer->setBarcodeHeight(100);
                // $printer->setBarcodeWidth(4);
                // $printer->barcode($parkingTransaction->barcode_number, 'CODE39');
                // $printer->text("\n");
                $printer->text($location->additional_info_ticket . "\n");
                $printer->cut();
                $printer->close();
            } catch (\Exception $e) {
                return response(['message' => 'GAGAL MENCETAK TIKET.' . $e->getMessage()], 500);
            }
        }

        return ['message' => 'SILAKAN AMBIL TIKET'];
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function search(Request $request)
    {
        // ambil transaksi terakhir yg blm tap out
        $data = ParkingTransaction::with(['member'])->when($request->barcode_number, function($q) use ($request) {
                return $q->where('barcode_number', $request->barcode_number)
                        ->orWhere('card_number', $request->barcode_number);
            })->where('time_out', null)
            ->orderBy('time_in', 'DESC')->first();

        if ($data) {
            // kalau member cek dulu ada yg masih blm tap out ga selain data yg ini
            if ($data->is_member) {
                ParkingTransaction::where('parking_member_id', $data->parking_member_id)
                    ->where('id', '!=', $data->id)
                    ->where('time_out', null)
                    ->update([
                        'time_out' => now(),
                        'operator' => $request->user()->name,
                        'user_id' => $request->user()->id,
                        'gate_out_id' => ParkingGate::where('type', 'OUT')->where('active', 1)->first()->id
                    ]);
            }

            return $data;
        }

        // member, tapi gak tap in karena rusak gate in
        $member = ParkingMember::where('card_number', $request->barcode_number)->first();

        if ($member)
        {
            if (!$member->is_active) {
                return response(['message' => 'KARTU TIDAK AKTIF'], 500);
            }

            if (strtotime($member->expiry_date) < strtotime(date('Y-m-d'))) {
                return response(['message' => 'KARTU SUDAH KEDALUARSA'], 500);
            }

            $data = [
                'barcode_number' => 'NOTAP',
                'vehicle_type' => $member->vehicles[0]->vehicle_type,
                'is_member' => 1,
                'parking_member_id' => $member->id,
                'time_in' => date('Y-m-d H:i:s'),
                'gate_in_id' => ParkingGate::where('type', 'IN')->where('active', 1)->first()->id,
                'card_number' => $member->card_number
            ];

            $trx = ParkingTransaction::create($data);
            return ParkingTransaction::with(['member'])->find($trx->id);
        }

        return response(['message' => 'NOMOR TIKET/KARTU INVALID'], 404);
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

        if ($request->is_member) {
            ParkingMember::where('id', $request->parking_member_id)->update([
                'last_transaction' => now()
            ]);
        }

        // value ini ga perlu diupdate
        unset($input['card_number']);
        unset($input['barcode_number']);

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
