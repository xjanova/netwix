@extends('errors.layout')
@section('code', '500')
@section('title', 'ระบบขัดข้องชั่วคราว')
@section('message', 'เราเจอปัญหาบางอย่างและกำลังเร่งแก้ไขอยู่ ลองรีเฟรชอีกครั้งในอีกสักครู่นะ')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">กลับหน้าแรก</a>
    <button class="btn btn-ghost" onclick="location.reload()">ลองใหม่อีกครั้ง</button>
@endsection
