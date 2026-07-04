@extends('errors.layout')
@section('code', '403')
@section('title', 'ไม่มีสิทธิ์เข้าถึงหน้านี้')
@section('message', 'หน้านี้อาจสงวนไว้เฉพาะสมาชิก Pro หรือผู้ดูแลระบบ ลองเข้าสู่ระบบด้วยบัญชีที่ถูกต้อง')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">กลับหน้าแรก</a>
    <a class="btn btn-ghost" href="{{ url('/login') }}">เข้าสู่ระบบ</a>
@endsection
