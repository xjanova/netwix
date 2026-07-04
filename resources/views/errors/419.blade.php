@extends('errors.layout')
@section('code', '419')
@section('title', 'เซสชันหมดอายุ')
@section('message', 'หน้านี้เปิดค้างไว้นานเกินไป เพื่อความปลอดภัย โปรดโหลดหน้าใหม่แล้วลองอีกครั้ง')
@section('actions')
    <button class="btn btn-primary" onclick="location.reload()">โหลดหน้าใหม่</button>
    <a class="btn btn-ghost" href="{{ url('/') }}">กลับหน้าแรก</a>
@endsection
