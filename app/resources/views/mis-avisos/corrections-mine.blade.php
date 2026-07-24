@extends('layouts.app')

@section('title', 'Mis propuestas de corrección - Tcloud')

@section('content')
<div class="p-6">

    <div class="mb-4">
        <a href="/mis-avisos" class="text-sm text-brand-600 hover:underline">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Mis Avisos
        </a>
    </div>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-800">Mis propuestas de corrección</h1>
        <p class="text-slate-500 mt-0.5">Historial de correcciones que has propuesto</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @if($corrections->count())
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Texto original</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Corrección</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Estado</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Fecha</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($corrections as $c)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 text-sm text-slate-700">{{ $c->wrong_text }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ $c->correct_text }}</td>
                    <td class="px-4 py-3">
                        @php
                            $badge = [
                                'pending' => 'bg-amber-100 text-amber-700',
                                'approved' => 'bg-green-100 text-green-700',
                                'rejected' => 'bg-red-100 text-red-700',
                                'merged' => 'bg-slate-100 text-slate-600',
                            ][$c->status] ?? 'bg-slate-100 text-slate-600';
                            $label = ['pending'=>'Pendiente','approved'=>'Aprobada','rejected'=>'Rechazada','merged'=>'Fusionada'][$c->status] ?? $c->status;
                        @endphp
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $badge }}">{{ $label }}</span>
                        @if($c->status === 'rejected' && $c->rejected_reason)
                            <p class="text-xs text-slate-400 mt-1">{{ $c->rejected_reason }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell text-sm text-slate-500">{{ $c->created_at->format('Y-m-d H:i') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-4 py-3">{{ $corrections->links() }}</div>
        @else
        <div class="text-center py-12 text-slate-400">
            <i class="fas fa-spell-check text-3xl mb-2 block text-slate-200"></i>
            <p class="font-medium">No has propuesto correcciones aún</p>
            <p class="text-sm mt-1">Reporta correcciones desde las alertas recibidas en Mis Avisos.</p>
        </div>
        @endif
    </div>
</div>
@endsection