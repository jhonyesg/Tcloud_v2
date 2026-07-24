/**
 * Interactive Tour Engine — Tcloud
 * Spotlight + tooltip positioning + step navigation
 * Usage: window.TcloudTour.start({ steps: [...], onComplete: fn })
 */
(function () {
    'use strict';

    var Tour = {
        steps: [],
        current: 0,
        active: false,
        overlay: null,
        spotlight: null,
        tooltip: null,
        onComplete: null,
        padding: 8,

        start: function (config) {
            this.steps = config.steps || [];
            this.current = 0;
            this.active = true;
            this.onComplete = config.onComplete || null;
            this._build();
            this._showStep();
        },

        dismiss: function () {
            this.active = false;
            if (this.overlay) { this.overlay.remove(); this.overlay = null; }
            if (this.tooltip) { this.tooltip.remove(); this.tooltip = null; }
            this.spotlight = null;
            document.body.style.overflow = '';
        },

        next: function () {
            if (this.current < this.steps.length - 1) {
                this.current++;
                this._showStep();
            } else {
                if (this.onComplete) this.onComplete();
                this.dismiss();
            }
        },

        prev: function () {
            if (this.current > 0) {
                this.current--;
                this._showStep();
            }
        },

        _build: function () {
            document.body.style.overflow = 'hidden';

            // Overlay
            this.overlay = document.createElement('div');
            this.overlay.style.cssText = [
                'position:fixed', 'inset:0', 'z-index:99998',
                'pointer-events:auto'
            ].join(';');

            // SVG spotlight (4 boxes that darken everything except target)
            var svgNS = 'http://www.w3.org/2000/svg';
            var svg = document.createElementNS(svgNS, 'svg');
            svg.setAttribute('width', '100%');
            svg.setAttribute('height', '100%');
            svg.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;';

            var defs = document.createElementNS(svgNS, 'defs');
            defs.innerHTML =
                '<mask id="tour-mask">' +
                  '<rect width="100%" height="100%" fill="white"/>' +
                  '<rect id="tour-hole" x="0" y="0" width="0" height="0" rx="8" fill="black"/>' +
                '</mask>';
            svg.appendChild(defs);

            var dark = document.createElementNS(svgNS, 'rect');
            dark.setAttribute('width', '100%');
            dark.setAttribute('height', '100%');
            dark.setAttribute('fill', 'rgba(0,0,0,0.55)');
            dark.setAttribute('mask', 'url(#tour-mask)');
            svg.appendChild(dark);

            this.overlay.appendChild(svg);
            this.spotlight = document.getElementById('tour-hole');

            // Click on overlay background = dismiss
            this.overlay.addEventListener('click', function (e) {
                if (e.target === svg || e.target === dark) {
                    Tour.dismiss();
                }
            });

            document.body.appendChild(this.overlay);

            // Tooltip container
            this.tooltip = document.createElement('div');
            this.tooltip.style.cssText = [
                'position:fixed', 'z-index:99999',
                'max-width:360px', 'min-width:280px',
                'background:#fff', 'border-radius:14px',
                'box-shadow:0 12px 48px rgba(0,0,0,0.25)',
                'padding:0', 'display:none',
                'font-family:system-ui,-apple-system,sans-serif',
                'transition:opacity 0.2s ease,transform 0.2s ease'
            ].join(';');
            document.body.appendChild(this.tooltip);
        },

        _showStep: function () {
            var step = this.steps[this.current];
            if (!step) { this.dismiss(); return; }

            var target = typeof step.selector === 'function' ? step.selector() : document.querySelector(step.selector);

            // Position spotlight
            if (target) {
                var rect = target.getBoundingClientRect();
                var p = this.padding;
                this.spotlight.setAttribute('x', rect.left - p);
                this.spotlight.setAttribute('y', rect.top - p);
                this.spotlight.setAttribute('width', rect.width + p * 2);
                this.spotlight.setAttribute('height', rect.height + p * 2);
                target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });

                // Re-measure after scroll
                var self = this;
                setTimeout(function () {
                    var r2 = target.getBoundingClientRect();
                    self.spotlight.setAttribute('x', r2.left - p);
                    self.spotlight.setAttribute('y', r2.top - p);
                    self.spotlight.setAttribute('width', r2.width + p * 2);
                    self.spotlight.setAttribute('height', r2.height + p * 2);
                    self._positionTooltip(r2, step.position || 'bottom');
                }, 250);
            } else {
                // No target — center tooltip
                this.spotlight.setAttribute('width', '0');
                this.spotlight.setAttribute('height', '0');
                this._positionTooltip(null, 'center');
            }

            // Build tooltip content
            var icon = step.icon || 'fa-info-circle';
            var total = this.steps.length;
            var pct = ((this.current + 1) / total) * 100;

            var html =
                '<div style="padding:20px 22px 16px;">' +
                    '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;">' +
                        '<div style="width:38px;height:38px;border-radius:10px;background:' + (step.color || '#6366f1') + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                            '<i class="fas ' + icon + '" style="color:#fff;font-size:16px;"></i>' +
                        '</div>' +
                        '<div style="flex:1;min-width:0;">' +
                            '<p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:' + (step.color || '#6366f1') + ';margin:0 0 2px;">' +
                                'Paso ' + (this.current + 1) + ' de ' + total +
                            '</p>' +
                            '<h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">' + step.title + '</h3>' +
                        '</div>' +
                    '</div>' +
                    '<div style="font-size:13px;color:#475569;line-height:1.6;margin-bottom:16px;">' + step.content + '</div>' +
                    '<div style="height:4px;background:#e2e8f0;border-radius:2px;margin-bottom:14px;overflow:hidden;">' +
                        '<div style="height:100%;width:' + pct + '%;background:' + (step.color || '#6366f1') + ';border-radius:2px;transition:width 0.3s ease;"></div>' +
                    '</div>' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
                        '<button id="tour-prev-btn" style="font-size:13px;color:#94a3b8;background:none;border:none;cursor:pointer;padding:6px 10px;border-radius:8px;' + (this.current === 0 ? 'visibility:hidden;' : '') + '">' +
                            '<i class="fas fa-arrow-left" style="margin-right:4px;"></i>Anterior' +
                        '</button>' +
                        '<button id="tour-skip-btn" style="font-size:12px;color:#cbd5e1;background:none;border:none;cursor:pointer;padding:6px 10px;">Saltar</button>' +
                        '<button id="tour-next-btn" style="font-size:13px;color:#fff;background:' + (step.color || '#6366f1') + ';border:none;border-radius:8px;padding:8px 18px;cursor:pointer;font-weight:600;">' +
                            (this.current < total - 1 ? 'Siguiente <i class="fas fa-arrow-right" style="margin-left:4px;"></i>' : 'Finalizar <i class="fas fa-check" style="margin-left:4px;"></i>') +
                        '</button>' +
                    '</div>' +
                '</div>';

            this.tooltip.innerHTML = html;
            this.tooltip.style.opacity = '0';
            this.tooltip.style.display = 'block';

            // Bind buttons
            var self = this;
            document.getElementById('tour-prev-btn').onclick = function () { self.prev(); };
            document.getElementById('tour-next-btn').onclick = function () { self.next(); };
            document.getElementById('tour-skip-btn').onclick = function () { self.dismiss(); };

            // Fade in
            requestAnimationFrame(function () {
                self.tooltip.style.opacity = '1';
            });

            // Re-position now too (before scroll completes)
            if (target) {
                var r3 = target.getBoundingClientRect();
                this._positionTooltip(r3, step.position || 'bottom');
            } else {
                this._positionTooltip(null, 'center');
            }

            // Run onShow callback
            if (step.onShow) step.onShow();
        },

        _positionTooltip: function (rect, placement) {
            if (!this.tooltip || this.tooltip.style.display === 'none') return;
            var tw = this.tooltip.offsetWidth || 320;
            var th = this.tooltip.offsetHeight || 200;
            var vw = window.innerWidth;
            var vh = window.innerHeight;
            var gap = 16;

            var x, y, arrowSide = '';

            if (!rect || placement === 'center') {
                x = (vw - tw) / 2;
                y = (vh - th) / 2;
                arrowSide = 'none';
            } else if (placement === 'bottom') {
                x = rect.left + rect.width / 2 - tw / 2;
                y = rect.bottom + gap;
                arrowSide = 'top';
            } else if (placement === 'top') {
                x = rect.left + rect.width / 2 - tw / 2;
                y = rect.top - th - gap;
                arrowSide = 'bottom';
            } else if (placement === 'right') {
                x = rect.right + gap;
                y = rect.top + rect.height / 2 - th / 2;
                arrowSide = 'left';
            } else if (placement === 'left') {
                x = rect.left - tw - gap;
                y = rect.top + rect.height / 2 - th / 2;
                arrowSide = 'right';
            }

            // Clamp to viewport
            x = Math.max(gap, Math.min(x, vw - tw - gap));
            y = Math.max(gap, Math.min(y, vh - th - gap));

            this.tooltip.style.left = x + 'px';
            this.tooltip.style.top = y + 'px';

            // Arrow
            this._addArrow(arrowSide, rect, x, y, tw, th);
        },

        _addArrow: function (side, rect, tipX, tipY, tw, th) {
            var old = this.tooltip.querySelector('.tour-arrow');
            if (old) old.remove();
            if (side === 'none' || !rect) return;

            var arrow = document.createElement('div');
            arrow.className = 'tour-arrow';
            arrow.style.cssText = 'position:absolute;width:14px;height:14px;background:#fff;transform:rotate(45deg);';

            if (side === 'top') {
                arrow.style.top = '-7px';
                arrow.style.left = (rect.left + rect.width / 2 - tipX - 7) + 'px';
                arrow.style.boxShadow = '-2px -2px 4px rgba(0,0,0,0.06)';
            } else if (side === 'bottom') {
                arrow.style.bottom = '-7px';
                arrow.style.left = (rect.left + rect.width / 2 - tipX - 7) + 'px';
                arrow.style.boxShadow = '2px 2px 4px rgba(0,0,0,0.06)';
            } else if (side === 'left') {
                arrow.style.left = '-7px';
                arrow.style.top = (rect.top + rect.height / 2 - tipY - 7) + 'px';
                arrow.style.boxShadow = '-2px 2px 4px rgba(0,0,0,0.06)';
            } else if (side === 'right') {
                arrow.style.right = '-7px';
                arrow.style.top = (rect.top + rect.height / 2 - tipY - 7) + 'px';
                arrow.style.boxShadow = '2px -2px 4px rgba(0,0,0,0.06)';
            }

            this.tooltip.appendChild(arrow);
        }
    };

    window.TcloudTour = Tour;
})();