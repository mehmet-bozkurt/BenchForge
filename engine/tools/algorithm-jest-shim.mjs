// Exercism JavaScript alistirmalari Jest ile yazilmistir. Bu dosya, ek bagimlilik kurmadan ayni testleri Node'un
// kendi test calistiricisinda (node:test) calistirmak icin Jest'in kullanilan kucuk bir bolumunu saglar.
// Desteklenen eslestiriciler SUPPORTED_MATCHERS listesindedir; baska eslestirici kullanan alistirmalar iceri
// aktarilmaz.
import assert from 'node:assert/strict';
import { isDeepStrictEqual } from 'node:util';
import { after, afterEach, before, beforeEach, describe, it, test } from 'node:test';

export { describe, it, test };
export const xtest = test.skip;
export const xit = it.skip;
export const xdescribe = describe.skip;
export const beforeAll = before;
export const afterAll = after;
export { afterEach, beforeEach };

export const SUPPORTED_MATCHERS = [
    'toBe',
    'toEqual',
    'toStrictEqual',
    'toBeTruthy',
    'toBeFalsy',
    'toBeNull',
    'toBeUndefined',
    'toBeDefined',
    'toBeNaN',
    'toBeCloseTo',
    'toContain',
    'toContainEqual',
    'toHaveLength',
    'toMatch',
    'toBeGreaterThan',
    'toBeGreaterThanOrEqual',
    'toBeLessThan',
    'toBeLessThanOrEqual',
    'toBeInstanceOf',
    'toThrow',
    'toHaveProperty',
];

function format(value) {
    try {
        return typeof value === 'string' ? JSON.stringify(value) : JSON.stringify(value, (key, item) => (item === undefined ? '__undefined__' : typeof item === 'bigint' ? `${item}n` : item)) ?? String(value);
    } catch {
        return String(value);
    }
}

// Jest'in toEqual kurali: prototipler ve tanimsiz (undefined) ozellikler yok sayilir, +0 ile -0 esittir.
export function looselyEqual(a, b) {
    if (Object.is(a, b) || (a === 0 && b === 0)) {
        return true;
    }

    if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) {
        return false;
    }

    if (Array.isArray(a) !== Array.isArray(b)) {
        return false;
    }

    if (a instanceof Date || b instanceof Date) {
        return a instanceof Date && b instanceof Date && a.getTime() === b.getTime();
    }

    if (a instanceof Map || b instanceof Map) {
        if (!(a instanceof Map && b instanceof Map) || a.size !== b.size) {
            return false;
        }

        for (const [key, value] of a) {
            if (!b.has(key) || !looselyEqual(value, b.get(key))) {
                return false;
            }
        }

        return true;
    }

    if (a instanceof Set || b instanceof Set) {
        if (!(a instanceof Set && b instanceof Set) || a.size !== b.size) {
            return false;
        }

        return [...a].every((item) => b.has(item) || [...b].some((other) => looselyEqual(item, other)));
    }

    if (Array.isArray(a)) {
        return a.length === b.length && a.every((item, index) => looselyEqual(item, b[index]));
    }

    const keys = (value) => Object.keys(value).filter((key) => value[key] !== undefined);
    const aKeys = keys(a);
    const bKeys = keys(b);

    return aKeys.length === bKeys.length && aKeys.every((key) => Object.hasOwn(b, key) && looselyEqual(a[key], b[key]));
}

function fail(message) {
    assert.fail(message);
}

function matchers(received, negate) {
    const check = (condition, expectation) => {
        if (negate ? condition : !condition) {
            fail(`${negate ? 'Beklenmeyen' : 'Beklenen'}: ${expectation}\nAlinan: ${format(received)}`);
        }
    };

    return {
        toBe: (expected) => check(Object.is(received, expected), `${format(expected)} ile ayni deger`),
        toEqual: (expected) => check(looselyEqual(received, expected), `${format(expected)} ile esit`),
        toStrictEqual: (expected) => check(isDeepStrictEqual(received, expected), `${format(expected)} ile kati esit`),
        toBeTruthy: () => check(Boolean(received), 'dogru (truthy) deger'),
        toBeFalsy: () => check(!received, 'yanlis (falsy) deger'),
        toBeNull: () => check(received === null, 'null'),
        toBeUndefined: () => check(received === undefined, 'undefined'),
        toBeDefined: () => check(received !== undefined, 'tanimli deger'),
        toBeNaN: () => check(Number.isNaN(received), 'NaN'),
        toBeCloseTo: (expected, digits = 2) => check(Math.abs(received - expected) < 10 ** -digits / 2, `${expected} degerine ${digits} basamakla yakin`),
        toContain: (expected) => check(typeof received === 'string' ? received.includes(expected) : Array.from(received).includes(expected), `${format(expected)} iceren`),
        toContainEqual: (expected) => check(Array.from(received).some((item) => looselyEqual(item, expected)), `${format(expected)} ile esit bir oge iceren`),
        toHaveLength: (expected) => check(received?.length === expected, `uzunlugu ${expected}`),
        toMatch: (expected) => check(typeof expected === 'string' ? String(received).includes(expected) : expected.test(String(received)), `${String(expected)} ile eslesen`),
        toBeGreaterThan: (expected) => check(received > expected, `${expected} degerinden buyuk`),
        toBeGreaterThanOrEqual: (expected) => check(received >= expected, `${expected} degerinden buyuk veya esit`),
        toBeLessThan: (expected) => check(received < expected, `${expected} degerinden kucuk`),
        toBeLessThanOrEqual: (expected) => check(received <= expected, `${expected} degerinden kucuk veya esit`),
        toBeInstanceOf: (expected) => check(received instanceof expected, `${expected?.name} ornegi`),
        toHaveProperty: (path, ...rest) => {
            const parts = Array.isArray(path) ? path : String(path).split('.');
            let current = received;
            let found = true;

            for (const part of parts) {
                if (current !== null && current !== undefined && part in Object(current)) {
                    current = current[part];
                } else {
                    found = false;
                    break;
                }
            }

            check(found && (rest.length === 0 || looselyEqual(current, rest[0])), `${parts.join('.')} ozelligi${rest.length > 0 ? ` ${format(rest[0])} degeriyle` : ''}`);
        },
        toThrow: (expected) => {
            let thrown;
            let didThrow = false;

            try {
                received();
            } catch (error) {
                didThrow = true;
                thrown = error;
            }

            let matches = didThrow;

            if (didThrow && expected !== undefined) {
                const message = String(thrown?.message ?? thrown);

                if (typeof expected === 'string') {
                    matches = message.includes(expected);
                } else if (expected instanceof RegExp) {
                    matches = expected.test(message);
                } else if (typeof expected === 'function') {
                    matches = thrown instanceof expected;
                } else if (expected instanceof Error) {
                    matches = message === expected.message;
                }
            }

            check(matches, expected === undefined ? 'bir hata firlatan islev' : `${String(expected?.message ?? expected)} hatasini firlatan islev`);
        },
    };
}

export function expect(received) {
    return { ...matchers(received, false), not: matchers(received, true) };
}
