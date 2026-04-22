class CWidgetCameraStatus extends CWidget {

	onInitialize() {
		super.onInitialize();
	}

	setContents(response) {
		super.setContents(response);
		this._applyLayout();
	}

	_applyLayout() {
		const root = this._body.querySelector('.camera-status-widget');
		if (!root) return;

		const cols = parseInt(root.dataset.columns, 10) || 6;
		const layout = root.dataset.layout || 'grid';

		if (layout === 'grid') {
			root.style.display = 'grid';
			root.style.gridTemplateColumns = `repeat(${cols}, minmax(0, 1fr))`;
			root.style.gap = '8px';
		} else {
			root.style.display = 'flex';
			root.style.flexDirection = 'column';
			root.style.gap = '4px';
			root.querySelectorAll('.camera-tile').forEach(t => {
				t.classList.add('list-mode');
			});
		}
	}

	getUpdateRequestData() {
		return {
			...super.getUpdateRequestData(),
			templateid: this._dashboard.templateid ?? undefined
		};
	}
}
