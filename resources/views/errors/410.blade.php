@extends('errors.layout')

@section('title', '410 – Nội Dung Đã Ngừng Phục Vụ Hoặc Đang Chuyển Đổi - ' . ($settings->name ?? 'Hải Phòng Tech'))

@section('badge_text', 'TRẠNG THÁI ĐƯỜNG DẪN 410')
@section('badge_class', 'badge_warning')
@section('code', '410')
@section('code_class', '')

@section('message_title', 'Đường dẫn này hiện không khả dụng trên hệ thống!')
@section('message_desc', 'Tài nguyên bạn yêu cầu đã ngừng phục vụ hoặc đang nằm trong lộ trình đồng bộ dữ liệu Giai đoạn 2. Quý khách vui lòng sử dụng thanh tìm kiếm hoặc liên hệ trực tiếp kỹ sư Hải Phòng Tech để được hỗ trợ ngay.')
