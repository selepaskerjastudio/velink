import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Cosmetic only — hides admin-only nav items and buttons. The server enforces
 * the actual restriction (see docs/ACCESS_CONTROL.md); a member who crafts
 * the request directly still gets a 403.
 */
export function useIsAdmin(): boolean {
    return usePage<SharedData>().props.auth?.can?.admin ?? false;
}
