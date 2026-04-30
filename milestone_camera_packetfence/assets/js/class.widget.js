/*
 * Camera Device (PacketFence) Widget
 *
 * Listens for camera selection from the Milestone Camera Status widget and
 * injects the selected camera's MAC and IP into the widget's update request,
 * so the PHP controller can query the PacketFence API for that device.
 */

class WidgetMilestoneCameraPacketFence extends CWidget {

    #cam_mac = null;
    #cam_ip = null;
    #cam_name = null;
    #cam_host = null;
    #camListener = null;

    onActivate() {
        // Restore last camera selection from sessionStorage
        try {
            const stored = sessionStorage.getItem('mcs_camera_selection');
            if (stored) {
                const {mac, ip, name, host} = JSON.parse(stored);
                this.#cam_mac  = mac  ?? null;
                this.#cam_ip   = ip   ?? null;
                this.#cam_name = name ?? null;
                this.#cam_host = host ?? null;
            }
        } catch (e) {}

        // Listen for camera clicks from the Milestone Camera Status widget
        this.#camListener = ({detail}) => {
            const {mac, ip, name, host} = detail ?? {};
            this.#cam_mac  = mac  ?? null;
            this.#cam_ip   = ip   ?? null;
            this.#cam_name = name ?? null;
            this.#cam_host = host ?? null;
            if (this.getState() === WIDGET_STATE_ACTIVE) {
                this._startUpdating();
            }
        };
        document.addEventListener('mcs:cameraSelected', this.#camListener);
    }

    onDeactivate() {
        if (this.#camListener) {
            document.removeEventListener('mcs:cameraSelected', this.#camListener);
            this.#camListener = null;
        }
    }

    getUpdateRequestData() {
        const data = super.getUpdateRequestData();
        if (this.#cam_mac  !== null) data.cam_mac  = this.#cam_mac;
        if (this.#cam_ip   !== null) data.cam_ip   = this.#cam_ip;
        if (this.#cam_name !== null) data.cam_name = this.#cam_name;
        if (this.#cam_host !== null) data.cam_host = this.#cam_host;
        return data;
    }
}
