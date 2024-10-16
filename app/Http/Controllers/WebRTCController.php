<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageEvent;
use App\Events\WebRTCEvent;
use App\Models\Chat;
use App\Models\IceCandidate;
use App\Models\Room;
use Illuminate\Http\Request;

class WebRTCController extends Controller
{
    public function createRoom(Request $request)
    {
        $validator = validator($request->all(), [
            'offer' => 'required|array',
            'offer.type' => 'required|string',
            'offer.sdp' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $offer = $request->input('offer');

        $room = new Room();
        $room->offer = json_encode($offer);
        $room->chat_id = $request->get('chat_id');
        $room->save();

        $chat = Chat::where('id', $request->get('chat_id'))
            ->with(
                [
                    'chat_video_room' => function ($sql) {
                        $sql->whereNull('answer')
                            ->orderBy('created_at', 'desc');
                    },
                    'chat_last_message',
                    'participants' => function ($sql) {
                        $sql->with('user');
                    },
                ]
            )
            ->first();

        $chat_controller = new ChatController();

        broadcast(new ChatMessageEvent(null, $chat, true));

        $chat_controller->notify_all_users_channels_listener($chat, $request);

        return response()->json(['roomId' => $room->id], 201);
    }

    //
    //
    //
    public function joinRoom(Request $request)
    {
        $validator = validator($request->all(), [
            'roomId' => 'required|integer|exists:rooms,id',
            // 'answer' => 'required|array',
            // 'answer.type' => 'required|string',
            // 'answer.sdp' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $roomId = $request->input('roomId');
        $room = Room::find($roomId);

        if ($request->get('answer')) {
            if ($room) {
                $answer = $request->input('answer');
                $room->answer = json_encode($answer);
                $room->save();

                $offer = json_decode($room->offer, true);
                $answer = json_decode($room->answer, true);

                broadcast(
                    new WebRTCEvent(
                        [
                            'answer' => $answer,
                        ],
                    )
                );

                return response()->json(['success' => true, 'offer' => $offer], 200);
            }
        } else if ($room) {
            $offer = json_decode($room->offer, true);
            return response()->json(['success' => true, 'offer' => $offer], 200);
        } else {

            return response()->json(['error' => 'Room does not exist'], 404);
        }
    }

    //
    //
    //
    public function addIceCandidate(Request $request)
    {
        $validator = validator($request->all(), [
            'roomId' => 'required|integer|exists:rooms,id',
            'candidate' => 'required|array',
            'candidate.candidate' => 'required|string',
            'candidate.sdpMid' => 'required|string',
            'candidate.sdpMLineIndex' => 'required|integer',
            'role' => 'required|in:caller,callee',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $roomId = $request->input('roomId');
        $candidate = $request->input('candidate');
        $role = $request->input('role');

        $iceCandidate = new IceCandidate();
        $iceCandidate->room_id = $roomId;
        $iceCandidate->candidate = json_encode($candidate);
        $iceCandidate->role = $role;
        $iceCandidate->save();

        broadcast(
            new WebRTCEvent(
                [
                    'role' => $role,
                    'candidate' => $candidate,
                ],
            ),
        );

        return response()->json(['success' => true], 201);
    }


    public function getIceCandidates(Request $request, $roomId, $role)
    {
        $validator = validator(['role' => $role], [
            'role' => 'required|in:caller,callee',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $candidates = IceCandidate::where('room_id', $roomId)->where('role', $role)->get();

        return response()->json(['candidates' => $candidates], 200);
    }

    public function candidat_data_handler(Request $request)
    {
        broadcast(new WebRTCEvent($request->get('data')));
    }
}
