{{--
  Widget flotante global que muestra los jobs en background activos
  en cualquier módulo. Vive en el layout (un único polling compartido)
  y se ancla a la esquina inferior derecha.

  Dependencias:
    - Alpine.js (CDN, ya cargado por el layout)
    - Endpoint GET /bg-jobs/active (BgJobsController)

  Contrato del endpoint:
    { jobs: [ { kind, runId, module, label, startedAt, progress, url } ] }
--}}
<div x-data="bgJobIndicator()" x-init="boot()" x-cloak>
    <template x-if="visibleJobs.length > 0">
        <div class="fixed bottom-4 right-4 z-40 w-80 max-w-[calc(100vw-2rem)] space-y-2 pointer-events-none">
            <template x-for="job in visibleJobs" :key="job.runId">
                <div class="bg-white rounded-xl shadow-lg border border-slate-200 p-3 pointer-events-auto"
                     :data-job-kind="job.kind" :data-job-runid="job.runId">
                    <div class="flex items-start justify-between gap-2 mb-1">
                        <div class="min-w-0">
                            <p class="text-[10px] uppercase tracking-wide font-semibold text-slate-400"
                               x-text="job.module"></p>
                            <p class="text-sm font-medium text-slate-800 truncate" x-text="job.label"></p>
                        </div>
                        <button type="button"
                                @click="dismiss(job.runId)"
                                class="text-slate-400 hover:text-slate-700 text-lg leading-none -mt-0.5"
                                title="Ocultar (la tarea sigue corriendo en el servidor)">×</button>
                    </div>

                    <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden mb-1.5">
                        <div class="h-full bg-brand-500 transition-all duration-300"
                             :style="'width:' + progressPct(job) + '%'"></div>
                    </div>

                    <p class="text-xs text-slate-500" x-html="progressLabel(job)"></p>

                    <div class="mt-2 flex items-center justify-between">
                        <a :href="job.url"
                           class="text-xs text-brand-600 hover:text-brand-800 font-medium hover:underline"
                           x-text="'Ver detalles →'"></a>
                        <span class="text-[10px] text-slate-400" x-text="relTime(job.startedAt)"></span>
                    </div>
                </div>
            </template>

            <template x-if="hiddenCount > 0">
                <button type="button"
                        @click="toggleCollapsed()"
                        class="w-full bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs py-1.5 rounded-lg pointer-events-auto">
                    <span x-show="!showAll" x-text="'+' + hiddenCount + ' más'"></span>
                    <span x-show="showAll" x-text="'Mostrar menos'"></span>
                </button>
            </template>
        </div>
    </template>
</div>

<script>
function bgJobIndicator() {
    return {
        jobs: [],
        dismissed: {},            // {runId: timestamp}
        emptyPolls: 0,
        polling: false,
        pollTimer: null,
        showAll: false,
        POLL_INTERVAL_MS: 5000,
        MAX_VISIBLE: 3,
        EMPTY_THRESHOLD: 2,        // polls vacíos antes de pausar
        ENDPOINT: '/bg-jobs/active',
        POLL_TIMEOUT_MS: 8000,

        async boot() {
            // Cargar dismissed persistido
            try {
                const stored = localStorage.getItem('bg_jobs:dismissed');
                if (stored) this.dismissed = JSON.parse(stored) || {};
            } catch (e) {}
            await this.poll();
            this.startTimer();
            this.attachVisibilityHandlers();
            // Limpieza periódica de dismissed antiguos (>1h se olvida)
            setInterval(() => {
                const cutoff = Date.now() - 3600_000;
                for (const [k, ts] of Object.entries(this.dismissed)) {
                    if (ts < cutoff) delete this.dismissed[k];
                }
                this.persistDismissed();
            }, 600_000);
        },

        startTimer() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => this.maybePoll(), this.POLL_INTERVAL_MS);
        },

        async maybePoll() {
            // Si la pestaña no está visible, no gastar ciclos
            if (document.visibilityState !== 'visible') return;
            // Si ya tenemos jobs visibles, polleamos; si dos polls vacíos, paramos
            if (this.jobs.length > 0) {
                await this.poll();
                return;
            }
            // Si todavía no hay jobs, polling suave (cada 5s)
            await this.poll();
        },

        async poll() {
            const ctl = new AbortController();
            const timer = setTimeout(() => ctl.abort(), this.POLL_TIMEOUT_MS);
            try {
                const r = await fetch(this.ENDPOINT, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    signal: ctl.signal
                });
                if (!r.ok) return;
                const data = await r.json();
                this.jobs = data.jobs || [];
                this.emptyPolls = this.jobs.length === 0 ? this.emptyPolls + 1 : 0;
                // Si el job dismissed ya no está en la respuesta, limpiarlo
                const active = new Set(this.jobs.map(j => j.runId));
                for (const rid of Object.keys(this.dismissed)) {
                    if (!active.has(rid)) delete this.dismissed[rid];
                }
                this.persistDismissed();
            } catch (e) {
                // Silenciar: el polling sigue vivo
                console.warn('bgJobIndicator poll failed:', e.message || e);
            } finally {
                clearTimeout(timer);
            }
        },

        attachVisibilityHandlers() {
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    this.emptyPolls = 0; // reset para reactivar polling
                    this.poll();
                }
            });
            // Reactivar tras interacción si llevamos mucho sin ver jobs
            ['click', 'keydown'].forEach(ev =>
                document.addEventListener(ev, () => {
                    if (this.jobCountRegressed()) this.poll();
                }, { passive: true })
            );
        },

        jobCountRegressed() {
            // Si el usuario regresa de inactividad, fuerce poll si fue hace >30s
            return Date.now() - (this.lastUserInteraction || 0) > 30000;
        },

        dismiss(runId) {
            this.dismissed[runId] = Date.now();
            this.persistDismissed();
        },

        isDismissed(runId) {
            return !!this.dismissed[runId];
        },

        persistDismissed() {
            try {
                localStorage.setItem('bg_jobs:dismissed', JSON.stringify(this.dismissed));
            } catch (e) {}
        },

        get visibleJobs() {
            const visible = this.jobs.filter(j => !this.isDismissed(j.runId));
            if (this.showAll || visible.length <= this.MAX_VISIBLE) return visible;
            return visible.slice(0, this.MAX_VISIBLE);
        },

        get hiddenCount() {
            const visible = this.jobs.filter(j => !this.isDismissed(j.runId));
            return Math.max(0, visible.length - this.MAX_VISIBLE);
        },

        toggleCollapsed() {
            this.showAll = !this.showAll;
        },

        progressPct(job) {
            const p = job.progress || {};
            if (job.kind === 'avisos-scan') {
                const s = p.scanned || 0;
                const e = p.estimate || 0;
                if (!e || e <= 0) return 0;
                return Math.min(100, Math.max(0, Math.round((s / e) * 100)));
            }
            if (job.kind === 'transcriptor-batch') {
                const pr = p.processed || 0;
                const t = p.total || 0;
                if (!t || t <= 0) return 0;
                return Math.min(100, Math.max(0, Math.round((pr / t) * 100)));
            }
            return 0;
        },

        progressLabel(job) {
            const p = job.progress || {};
            if (job.kind === 'avisos-scan') {
                const s = p.scanned || 0;
                const h = p.hits_new || 0;
                const f = p.failed || 0;
                const est = p.estimate ? ` de ${p.estimate}` : '';
                return `<span class="font-semibold text-slate-700">${s.toLocaleString('es-CO')}${est}</span> escaneadas · <span class="font-semibold text-violet-700">${h.toLocaleString('es-CO')}</span> hits${f > 0 ? ` · <span class="text-red-600">${f} fallos</span>` : ''}`;
            }
            if (job.kind === 'transcriptor-batch') {
                const pr = p.processed || 0;
                const t = p.total || 0;
                const err = p.errors || 0;
                // fix-A: cuando el batch termina, el label deja de mostrar
                // "X/Y archivos" y pasa a un resumen accionable:
                // "✓ N pendientes, M encolados" (o "✗ Error" si status=error).
                if (p.is_terminal) {
                    if (p.status === 'error') {
                        return `<span class="text-red-600 font-semibold">Error</span>${err > 0 ? ` · ${err} storages con fallo` : ''}`;
                    }
                    const created = p.pending_created || 0;
                    const queued = p.dispatched || 0;
                    return `<span class="text-emerald-700 font-semibold">✓ ${created}</span> pendientes · <span class="text-slate-700">${queued}</span> encolados${err > 0 ? ` · <span class="text-red-600">${err} errores</span>` : ''}`;
                }
                return `<span class="font-semibold text-slate-700">${pr}</span> / ${t} storages${err > 0 ? ` · <span class="text-red-600">${err} errores</span>` : ''}`;
            }
            return '';
        },

        relTime(iso) {
            if (!iso) return '';
            try {
                const d = new Date(iso);
                const secs = Math.floor((Date.now() - d.getTime()) / 1000);
                if (secs < 60) return `hace ${secs}s`;
                if (secs < 3600) return `hace ${Math.floor(secs/60)}m`;
                if (secs < 86400) return `hace ${Math.floor(secs/3600)}h`;
                return d.toLocaleString();
            } catch (e) { return ''; }
        },
    };
}
</script>
