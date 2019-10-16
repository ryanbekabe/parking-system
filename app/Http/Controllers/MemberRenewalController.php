<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\MemberRenewal;
use App\Http\Requests\MemberRenewalRequest;
use App\ParkingGate;

class MemberRenewalController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $sort = $request->sort ? $request->sort : 'id';
        $order = $request->order == 'ascending' ? 'asc' : 'desc';

        return MemberRenewal::selectRaw('
                member_renewals.*,
                parking_members.name AS member_name,
                parking_members.card_number AS card_number,
                users.name AS operator
            ')
            ->join('parking_members', 'parking_members.id', '=', 'member_renewals.parking_member_id')
            ->join('users', 'users.id', '=', 'member_renewals.user_id')
            ->when($request->keyword, function($q) use ($request) {
                return $q->where('parking_members.name', 'LIKE', '%'.$request->keyword.'%')
                    ->orWhere('parking_members.card_number', 'LIKE', '%'.$request->keyword.'%')
                    ->orWhere('users.name', 'LIKE', '%'.$request->keyword.'%');
            })
            ->orderBy($sort, $order)->paginate($request->pageSize);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(MemberRenewalRequest $request)
    {
        $input = $request->all();
        $input['user_id'] = auth()->user()->id;
        return MemberRenewal::create($input);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(MemberRenewalRequest $request, MemberRenewal $memberRenewal)
    {
        $memberRenewal->update($request->all());
        return $memberRenewal;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(MemberRenewal $memberRenewal)
    {
        $memberRenewal->delete();
        return ['message' => 'Data telah dihapus'];
    }

    public function printSlip(MemberRenewal $memberRenewal)
    {
        $parkingGate = ParkingGate::where('type', 'OUT')->where('active', 1)->first();

        if (!$parkingGate) {
            return response(['message' => 'TIDAK ADA PRINTER YANG DIPILIH'], 404);
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
            $printer->text("SLIP PEMBAYARAN KEANGGOTAAN\n");
            $printer->text("\n\n");

            $printer->setJustification(Printer::JUSTIFY_LEFT);
            $printer->text(str_pad('TANGGAL TRX', 15, ' ') . ' : ' . $memberRenewal->created_at . "\n");
            $printer->text(str_pad('NAMA MEMBER', 15, ' ') . ' : ' . $memberRenewal->parkingMember->name . "\n");
            $printer->text(str_pad('DARI TANGGAL', 15, ' ') . ' : ' . $memberRenewal->from_date . "\n");
            $printer->text(str_pad('SAMPAI TANGGAL', 15, ' ') . ' : ' . $memberRenewal->to_date . "\n");
            $printer->text(str_pad('JUMLAH', 15, ' ') . ' : ' .'Rp. ' . number_format($memberRenewal->amount, 0, ',', '.') . ",-\n");
            $printer->text(str_pad('PETUGAS', 15, ' ') . ' : ' . strtoupper($memberRenewal->user->name) . "\n\n");
            $printer->cut();
            $printer->close();
        } catch (\Exception $e) {
            return response(['message' => 'GAGAL MENCETAK SLIP.' . $e->getMessage()], 500);
        }

        return ['message' => 'SILAKAN AMBIL SLIP'];
    }
}
