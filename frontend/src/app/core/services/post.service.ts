import { Injectable, inject } from '@angular/core';

import { ApiService } from './api.service';
import { Post, PostListResponse } from '../models/post.model';

@Injectable({ providedIn: 'root' })
export class PostService {
  private readonly api = inject(ApiService);

  list(page = 1, perPage = 20) {
    return this.api.get<PostListResponse>('/posts', { page, perPage });
  }

  get(id: number) {
    return this.api.get<Post>(`/posts/${id}`);
  }

  create(productDescription: string) {
    return this.api.post<Post>('/posts', { productDescription });
  }

  update(id: number, payload: Partial<Pick<Post, 'productDescription' | 'finalCaption'>>) {
    return this.api.put<Post>(`/posts/${id}`, payload);
  }

  generateCaption(id: number) {
    return this.api.post<{ generatedCaption: string }>(`/posts/${id}/generate-caption`);
  }

  delete(id: number) {
    return this.api.delete<void>(`/posts/${id}`);
  }

  uploadMedia(id: number, file: File) {
    const formData = new FormData();
    formData.append('media', file);

    return this.api.post<{ mediaFilename: string; mediaType: 'image' | 'video' }>(
      `/posts/${id}/media`,
      formData
    );
  }

  deleteMedia(id: number) {
    return this.api.delete<void>(`/posts/${id}/media`);
  }

  publishFacebook(id: number) {
    return this.api.post<Post>(`/posts/${id}/publish/facebook`);
  }

  publishInstagram(id: number) {
    return this.api.post<Post>(`/posts/${id}/publish/instagram`);
  }

  publishBoth(id: number) {
    return this.api.post<{
      facebook: { status: string; error: string | null };
      instagram: { status: string; error: string | null };
      post: Post;
    }>(`/posts/${id}/publish`);
  }
}
