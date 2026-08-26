export type PostStatus = 'draft' | 'publishing' | 'published' | 'failed' | 'partially_published';
export type PlatformStatus = 'not_attempted' | 'pending' | 'success' | 'failed';

export interface Post {
  id: number;
  productDescription: string;
  generatedCaption: string | null;
  finalCaption: string | null;
  mediaFilename: string | null;
  mediaType: 'image' | 'video' | null;
  status: PostStatus;
  fbStatus: PlatformStatus;
  fbError: string | null;
  igStatus: PlatformStatus;
  igError: string | null;
  createdAt: string;
}

export interface PostListResponse {
  items: Post[];
  total: number;
  page: number;
  perPage: number;
}
