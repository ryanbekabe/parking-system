<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Notification;

class NotificationController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:1')->except([
            'index', 'update', 'store',
            'unreadNotification',
            'markAllAsRead'
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return Notification::when($request->keyword, function ($q) use ($request) {
                return $q->where('message', 'LIKE', '%' . $request->keyword . '%');
            })->when($request->read == 0, function($q) {
                return $q->where('read', 0);
            })->when($request->dateRange, function($q) use ($request) {
                return $q->whereRaw('DATE(updated_at) BETWEEN "'.$request->dateRange[0].'" AND "'.$request->dateRange[1].'"');
            })->orderBy($request->sort ? $request->sort : 'created_at', $request->order == 'ascending' ? 'asc' : 'desc')
            ->paginate($request->pageSize);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required',
            'parking_gate_id' => 'required|exists:parking_gates,id'
        ]);

        return Notification::create($request->all());
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Notification $notification)
    {
        $notification->update($request->all());
        return $notification;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Notification $notification)
    {
        $notification->delete();
        return ['message' => 'Data telah dihapus.'];
    }

    public function clearNotification()
    {
        Notification::truncate();
        return ['message' => 'Data telah dihapus.'];
    }

    public function unreadNotification()
    {
        return Notification::where('read', 0)->orderBy('created_at', 'desc')->get();
    }

    public function markAllAsRead()
    {
        Notification::where('read', 0)->update(['read' => 1]);
        return ['message' => 'Data telah disimpan'];
    }
}
