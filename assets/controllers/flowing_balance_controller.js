import { Controller } from '@hotwired/stimulus';

/*
 * Animates a real-time incrementing balance: balance + flowRate × (now - timestamp).
 *
 * Adapted from the Superfluid flowing-balances pattern (requestAnimationFrame, throttled
 * ticks) but using plain floating-point math instead of BigInt/wei: values here are already
 * human-scaled USDC decimals (small dollar amounts), not raw 18-decimal on-chain integers,
 * so float precision is more than sufficient and keeps the client-side code simple.
 */
export default class extends Controller {
    static values = {
        balance: Number,
        timestamp: Number,
        flowRatePerSecond: Number,
        decimals: { type: Number, default: 6 },
    };

    connect() {
        if (!this.flowRatePerSecondValue) {
            this.render(this.balanceValue);

            return;
        }

        this.lastTick = 0;
        this.tick = this.tick.bind(this);
        this.rafId = requestAnimationFrame(this.tick);
    }

    disconnect() {
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
        }
    }

    tick(now) {
        this.rafId = requestAnimationFrame(this.tick);

        if (now - this.lastTick < 200) {
            return;
        }
        this.lastTick = now;

        const elapsedSeconds = Date.now() / 1000 - this.timestampValue;
        const current = this.balanceValue + this.flowRatePerSecondValue * elapsedSeconds;
        this.render(current);
    }

    render(value) {
        const [integerPart, decimalPart] = value.toFixed(this.decimalsValue).split('.');
        const withSeparators = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        this.element.textContent = decimalPart ? `${withSeparators}.${decimalPart}` : withSeparators;
    }
}
