<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FileToolsAdminController extends Controller
{
    public function index()
    {
        return view('admin.file-tools.index');
    }

    public function users()
    {
        return view('admin.file-tools.users');
    }
}
