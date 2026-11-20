import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        this.source = new EventSource(this.urlValue, { withCredentials: true });
        this.source.addEventListener('proposal.updated', () => window.location.reload());
    }

    disconnect() {
        this.source?.close();
    }
}
