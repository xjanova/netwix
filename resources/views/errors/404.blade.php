@extends('errors.layout')
@section('code', '404')
@section('title', 'ไม่พบหน้าที่คุณค้นหา')
@section('message', 'ลิงก์อาจหมดอายุ ถูกย้าย หรือพิมพ์ผิด — แต่ยังมีหนังและซีรีส์อีกเพียบรอคุณอยู่')
@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">กลับหน้าแรก</a>
    <a class="btn btn-ghost" href="{{ url('/browse') }}">เลือกดูคอนเทนต์</a>
@endsection
