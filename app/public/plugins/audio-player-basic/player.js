(function() {
    'use strict';

    function audio_player_basic_init(options) {
        var container = options.container;
        var file = options.file;
        var config = options.config || {};

        var autoplayAttr = config.autoplay ? ' autoplay' : '';

        container.innerHTML = `
            <div class="audio-player-basic">
                <div class="audio-player-basic-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55C7.79 13 6 14.79 6 17s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>
                    </svg>
                </div>
                <div class="audio-player-basic-header">
                    <span class="audio-player-basic-filename">${escapeHtml(file.name)}</span>
                    <span class="audio-player-basic-filesize">${formatSize(file.size)}</span>
                </div>
                <div class="audio-player-basic-controls">
                    <audio id="apb-audio" class="audio-player-basic-audio" controls${autoplayAttr}>
                        <source src="/media/${file.id}/preview" type="${file.mime_type || 'audio/mpeg'}">
                        Tu navegador no soporta el elemento de audio.
                    </audio>
                </div>
                <div class="audio-player-basic-footer">
                    <a class="audio-player-basic-download" href="/files/${file.id}/download">&#x2193; Descargar</a>
                </div>
            </div>
        `;

        function formatSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }
    }

    if (typeof window !== 'undefined') {
        window.audio_player_basic_init = audio_player_basic_init;
    }
})();
