import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';

import { PostService } from '../../core/services/post.service';
import { Post } from '../../core/models/post.model';

const ALLOWED_MEDIA_TYPES = ['image/jpeg', 'image/png', 'video/mp4'];
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
const MAX_VIDEO_BYTES = 100 * 1024 * 1024;

/**
 * Handles post creation ("new") and the describe → generate → review → media →
 * publish flow for an existing draft. The draft is created as soon as the
 * description is first saved (route swaps from /posts/new to /posts/:id) so a
 * refresh never loses progress.
 */
@Component({
  selector: 'app-post-wizard-page',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './post-wizard.page.html',
  styleUrl: './post-wizard.page.scss'
})
export class PostWizardPage implements OnInit {
  private readonly postService = inject(PostService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly isNew = signal(true);
  readonly post = signal<Post | null>(null);
  readonly loading = signal(true);

  readonly creatingDraft = signal(false);
  readonly savingDescription = signal(false);
  readonly generating = signal(false);
  readonly savingCaption = signal(false);

  readonly errorMessage = signal<string | null>(null);
  readonly captionSaved = signal(false);

  readonly uploadingMedia = signal(false);
  readonly mediaError = signal<string | null>(null);
  readonly localPreviewUrl = signal<string | null>(null);

  readonly publishingFacebook = signal(false);
  readonly publishingInstagram = signal(false);
  readonly publishingBoth = signal(false);

  productDescription = '';
  captionDraft = '';

  ngOnInit(): void {
    const idParam = this.route.snapshot.paramMap.get('id');

    if (idParam === null || idParam === 'new') {
      this.isNew.set(true);
      this.loading.set(false);
      return;
    }

    this.isNew.set(false);
    this.loadPost(Number(idParam));
  }

  private loadPost(id: number): void {
    this.loading.set(true);
    this.postService.get(id).subscribe((post) => {
      this.applyPost(post);
      this.loading.set(false);
    });
  }

  private applyPost(post: Post): void {
    this.post.set(post);
    this.productDescription = post.productDescription;
    this.captionDraft = post.finalCaption ?? post.generatedCaption ?? '';
  }

  createDraft(): void {
    if (this.productDescription.trim() === '' || this.creatingDraft()) {
      return;
    }

    this.creatingDraft.set(true);
    this.errorMessage.set(null);

    this.postService.create(this.productDescription.trim()).subscribe({
      next: (post) => {
        this.creatingDraft.set(false);
        void this.router.navigate(['/posts', post.id], { replaceUrl: true });
      },
      error: (error: unknown) => {
        this.creatingDraft.set(false);
        this.errorMessage.set(this.messageFor(error));
      }
    });
  }

  saveDescription(): void {
    const post = this.post();

    if (post === null || this.savingDescription()) {
      return;
    }

    this.savingDescription.set(true);
    this.postService.update(post.id, { productDescription: this.productDescription }).subscribe({
      next: (updated) => {
        this.savingDescription.set(false);
        this.applyPost(updated);
      },
      error: (error: unknown) => {
        this.savingDescription.set(false);
        this.errorMessage.set(this.messageFor(error));
      }
    });
  }

  generateCaption(): void {
    const post = this.post();

    if (post === null || this.generating()) {
      return;
    }

    this.generating.set(true);
    this.errorMessage.set(null);
    this.captionSaved.set(false);

    this.postService.generateCaption(post.id).subscribe({
      next: ({ generatedCaption }) => {
        this.generating.set(false);
        this.captionDraft = generatedCaption;
        this.post.update((current) => (current ? { ...current, generatedCaption } : current));
      },
      error: (error: unknown) => {
        this.generating.set(false);
        this.errorMessage.set(this.messageFor(error));
      }
    });
  }

  saveCaption(): void {
    const post = this.post();

    if (post === null || this.savingCaption()) {
      return;
    }

    this.savingCaption.set(true);
    this.errorMessage.set(null);

    this.postService.update(post.id, { finalCaption: this.captionDraft }).subscribe({
      next: (updated) => {
        this.savingCaption.set(false);
        this.captionSaved.set(true);
        this.applyPost(updated);
      },
      error: (error: unknown) => {
        this.savingCaption.set(false);
        this.errorMessage.set(this.messageFor(error));
      }
    });
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
      return;
    }

    this.mediaError.set(null);

    if (!ALLOWED_MEDIA_TYPES.includes(file.type)) {
      this.mediaError.set('Wspierane formaty: JPEG, PNG, MP4.');
      return;
    }

    const isVideo = file.type === 'video/mp4';
    const maxBytes = isVideo ? MAX_VIDEO_BYTES : MAX_IMAGE_BYTES;

    if (file.size > maxBytes) {
      this.mediaError.set(`Plik jest zbyt duży (limit ${isVideo ? '100 MB' : '10 MB'}).`);
      return;
    }

    this.uploadMedia(file);
  }

  private uploadMedia(file: File): void {
    const post = this.post();

    if (post === null || this.uploadingMedia()) {
      return;
    }

    this.uploadingMedia.set(true);
    const previousPreview = this.localPreviewUrl();
    this.localPreviewUrl.set(URL.createObjectURL(file));

    this.postService.uploadMedia(post.id, file).subscribe({
      next: ({ mediaFilename, mediaType }) => {
        this.uploadingMedia.set(false);
        if (previousPreview) {
          URL.revokeObjectURL(previousPreview);
        }
        this.post.update((current) => (current ? { ...current, mediaFilename, mediaType } : current));
      },
      error: (error: unknown) => {
        this.uploadingMedia.set(false);
        this.localPreviewUrl.set(previousPreview);
        this.mediaError.set(this.messageFor(error));
      }
    });
  }

  removeMedia(): void {
    const post = this.post();

    if (post === null) {
      return;
    }

    this.postService.deleteMedia(post.id).subscribe(() => {
      const preview = this.localPreviewUrl();
      if (preview) {
        URL.revokeObjectURL(preview);
      }
      this.localPreviewUrl.set(null);
      this.post.update((current) => (current ? { ...current, mediaFilename: null, mediaType: null } : current));
    });
  }

  publishFacebook(): void {
    const post = this.post();

    if (post === null || this.publishingFacebook()) {
      return;
    }

    this.publishingFacebook.set(true);
    this.postService.publishFacebook(post.id).subscribe({
      next: (updated) => {
        this.publishingFacebook.set(false);
        this.post.set(updated);
      },
      error: (error: unknown) => {
        this.publishingFacebook.set(false);
        this.errorMessage.set(this.messageFor(error));
      }
    });
  }

  publishInstagram(): void {
    const post = this.post();

    if (post === null || this.publishingInstagram()) {
      return;
    }

    this.publishingInstagram.set(true);
    this.postService.publishInstagram(post.id).subscribe({
      next: (updated) => {
        this.publishingInstagram.set(false);
        this.post.set(updated);
      },
      error: (error: unknown) => {
        this.publishingInstagram.set(false);
        this.errorMessage.set(this.messageFor(error));
      }
    });
  }

  publishBoth(): void {
    const post = this.post();

    if (post === null || this.publishingBoth()) {
      return;
    }

    this.publishingBoth.set(true);
    this.postService.publishBoth(post.id).subscribe({
      next: ({ post: updated }) => {
        this.publishingBoth.set(false);
        this.post.set(updated);
      },
      error: (error: unknown) => {
        this.publishingBoth.set(false);
        this.errorMessage.set(this.messageFor(error));
      }
    });
  }

  private messageFor(error: unknown): string {
    if (error instanceof HttpErrorResponse && error.error?.error?.message) {
      return error.error.error.message as string;
    }

    return 'Wystąpił błąd. Spróbuj ponownie.';
  }
}
