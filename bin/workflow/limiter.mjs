// Concurrency helpers of the workflow worker: a FIFO semaphore whose waits end when a signal aborts,
// an interruptible sleep, a sliding-window pool and abort controllers that follow a parent signal.

/**
 * Counting semaphore with first-in, first-out waiters. `acquire(signal)` resolves to a release function,
 * or to null when the signal aborts before a permit is free (the waiter then leaves the queue).
 */
export class Semaphore {
  constructor(limit) {
    if (!Number.isSafeInteger(limit) || limit < 1) {
      throw new Error("A semaphore needs a positive integer limit");
    }

    this.limit = limit;
    this.inUse = 0;
    this.queue = [];
  }

  get active() {
    return this.inUse;
  }

  get waiting() {
    return this.queue.length;
  }

  acquire(signal = null) {
    if (signal?.aborted) return Promise.resolve(null);

    if (this.inUse < this.limit && this.queue.length === 0) {
      this.inUse += 1;
      return Promise.resolve(this.releaser());
    }

    return new Promise((resolve) => {
      const waiter = { resolve, signal, onAbort: null };

      if (signal) {
        waiter.onAbort = () => {
          const index = this.queue.indexOf(waiter);
          if (index !== -1) this.queue.splice(index, 1);
          resolve(null);
        };
        signal.addEventListener("abort", waiter.onAbort, { once: true });
      }

      this.queue.push(waiter);
    });
  }

  releaser() {
    let released = false;

    return () => {
      if (released) return;
      released = true;
      this.inUse -= 1;
      this.dispatch();
    };
  }

  dispatch() {
    while (this.inUse < this.limit && this.queue.length > 0) {
      const waiter = this.queue.shift();
      if (waiter.signal && waiter.onAbort) waiter.signal.removeEventListener("abort", waiter.onAbort);
      if (waiter.signal?.aborted) {
        waiter.resolve(null);
        continue;
      }
      this.inUse += 1;
      waiter.resolve(this.releaser());
    }
  }
}

/** Waits `ms` milliseconds; resolves true after the full wait, false as soon as the signal aborts. */
export function sleep(ms, signal = null) {
  if (signal?.aborted) return Promise.resolve(false);
  if (!(ms > 0)) return Promise.resolve(true);

  return new Promise((resolve) => {
    const onAbort = () => {
      clearTimeout(timer);
      resolve(false);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", onAbort);
      resolve(true);
    }, ms);

    signal?.addEventListener("abort", onAbort, { once: true });
  });
}

/**
 * Runs `worker(item, index)` for the items in order with at most `limit` running at once (a sliding
 * window). No new item starts once `canStart()` is false. Every started worker is awaited; the first
 * failure is rethrown afterwards and stops further starts.
 */
export async function runPool(items, limit, worker, canStart = () => true) {
  let next = 0;
  let failure = null;

  async function lane() {
    while (failure === null && next < items.length && canStart()) {
      const index = next;
      next += 1;
      try {
        await worker(items[index], index);
      } catch (error) {
        failure ??= { error };
      }
    }
  }

  const lanes = [];
  for (let count = 0; count < Math.min(Math.max(1, limit), items.length); count += 1) {
    lanes.push(lane());
  }

  await Promise.all(lanes);
  if (failure !== null) throw failure.error;
}

/** An AbortController that also aborts when `parent` aborts. */
export function childController(parent = null) {
  const controller = new AbortController();

  if (parent?.aborted) {
    controller.abort(parent.reason);
  } else if (parent) {
    const onAbort = () => controller.abort(parent.reason);
    parent.addEventListener("abort", onAbort, { once: true });
    controller.signal.addEventListener("abort", () => parent.removeEventListener("abort", onAbort), { once: true });
  }

  return controller;
}
