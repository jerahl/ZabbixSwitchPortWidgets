/**
 * Camera Status Grid - client-side widget class.
 *
 * Follows the Zabbix 7.x CWidget subclass pattern. On refresh, the server
 * re-renders the body; this class wires up interactions (click-through,
 * tooltip enhancements) after each render.
 */
class WidgetCameraStatus extends CWidget {

    onInitialize() {
        super.onInitialize();
        this._click_handler = this._onTileClick.bind(this);
    }

    promiseReady() {
        return super.promiseReady().then(() => this._wireUp());
    }

    onResize() {
        super.onResize();
        // Recompute grid column count based on current body width.
        this._relayout();
    }

    _wireUp() {
        const root = this._body.querySelector('.camera-status-widget');
        if (!root) return;

        // Click on a tile → jump to the host's Latest Data view.
        root.querySelectorAll('.cam-tile').forEach((tile) => {
            tile.removeEventListener('click', this._click_handler);
            tile.addEventListener('click', this._click_handler);
        });

        this._relayout();
    }

    _onTileClick(ev) {
        const tile = ev.currentTarget;
        const hostid = tile.dataset.hostid;
        if (!hostid) return;

        // Ctrl/Cmd-click opens in new tab, plain click same tab.
        const url = `zabbix.php?action=latest.view&filter_hostids%5B%5D=${hostid}&filter_set=1`;
        if (ev.ctrlKey || ev.metaKey) {
            window.open(url, '_blank');
        } else {
            window.location.href = url;
        }
    }

    _relayout() {
        const root = this._body.querySelector('.camera-status-widget');
        if (!root) return;
        const grid = root.querySelector('.cam-grid');
        if (!grid) return;

        // Pick a target tile width from the tile size attribute.
        const sizeAttr = root.getAttribute('data-tile-size') || 'cam-tile--md';
        const targetW = sizeAttr.includes('lg') ? 200 : sizeAttr.includes('sm') ? 110 : 150;

        const available = grid.clientWidth;
        const cols = Math.max(1, Math.floor(available / (targetW + 8)));
        grid.style.gridTemplateColumns = `repeat(${cols}, minmax(${targetW}px, 1fr))`;
    }
}
