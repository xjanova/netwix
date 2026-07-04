@extends('errors.layout')
@section('code', '503')
@section('title', 'กำลังอัปเดตระบบ')
@section('message', 'NetWix กำลังปรับปรุงให้ดียิ่งขึ้น เดี๋ยวกลับมาในอีกไม่กี่นาที ขอบคุณที่รอนะ 💜')
@section('actions')
    <button class="btn btn-primary" onclick="location.reload()">โหลดใหม่</button>
@endsection
