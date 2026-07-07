<div x-cloak x-show="showModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" x-transition>
    <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl" @click.away="showModal = false">
        <div class="p-6">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-lg font-bold text-slate-800">Reportar corrección</h2>
                <button @click="showModal = false" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Texto del transcriptor</label>
                    <input type="text" x-model="form.wrong_text" readonly
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-600">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1.5 uppercase tracking-wide">Corrección propuesta</label>
                    <input type="text" x-model="form.correct_text" @keydown.enter="submitCorrection()"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500 outline-none">
                </div>
            </div>

            <div class="flex gap-3 mt-6">
                <button @click="submitCorrection()" class="flex-1 py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-medium transition-colors">Enviar para revisión</button>
                <button @click="showModal = false" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-medium transition-colors">Cancelar</button>
            </div>
        </div>
    </div>
</div>