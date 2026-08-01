import { apiRequest } from './client';

export function sendFeedback(type: 'bug' | 'suggest', content: string, screenshotBase64?: string) {
  return apiRequest<null>('/api/v1/feedback', {
    method: 'POST',
    body: JSON.stringify({
      type,
      content,
      screenshot_base64: screenshotBase64 || '',
    }),
  });
}
