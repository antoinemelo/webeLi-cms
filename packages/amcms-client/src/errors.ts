import type { PublicApiErrorPayload } from './types';

export class AmCmsApiError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly details?: unknown;
  readonly requestId?: string;
  readonly payload?: PublicApiErrorPayload | unknown;

  constructor(params: {
    status: number;
    message: string;
    code?: string;
    details?: unknown;
    requestId?: string;
    payload?: PublicApiErrorPayload | unknown;
  }) {
    super(params.message);
    this.name = 'AmCmsApiError';
    this.status = params.status;
    this.code = params.code;
    this.details = params.details;
    this.requestId = params.requestId;
    this.payload = params.payload;
  }
}

export function isAmCmsApiError(error: unknown): error is AmCmsApiError {
  return error instanceof AmCmsApiError;
}
