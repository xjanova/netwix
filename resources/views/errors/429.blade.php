@extends('errors.layout')
@section('code', '429')
@section('title', 'ทำรายการเร็วเกินไป')
@section('message', 'คุณส่งคำขอถี่เกินไป พักสักครู่ประมาณ 1 นาที แล้วค่อยลองใหม่อีกครั้งนะ')
@section('actions')
    <button class="btn btn-primary" onclick="location.reload()">ลองใหม่</button>
    <a class="btn btn-ghost" href="{{ url('/') }}">กลับหน้าแรก</a>
@endsection
