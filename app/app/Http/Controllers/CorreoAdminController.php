<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CorreoAdminController extends Controller
{
    public function index()
    {
        return view('admin.correo');
    }
}
