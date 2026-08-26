import { Component, OnInit, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../core/services/auth.service';
import { PostService } from '../../core/services/post.service';
import { Post } from '../../core/models/post.model';

@Component({
  selector: 'app-post-history-page',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './post-history.page.html',
  styleUrl: './post-history.page.scss'
})
export class PostHistoryPage implements OnInit {
  readonly auth = inject(AuthService);
  private readonly postService = inject(PostService);
  private readonly router = inject(Router);

  readonly posts = signal<Post[]>([]);
  readonly loading = signal(true);

  ngOnInit(): void {
    this.loadPosts();
  }

  private loadPosts(): void {
    this.loading.set(true);
    this.postService.list().subscribe((response) => {
      this.posts.set(response.items);
      this.loading.set(false);
    });
  }

  statusLabel(status: Post['status']): string {
    const labels: Record<Post['status'], string> = {
      draft: 'Szkic',
      publishing: 'Publikowanie…',
      published: 'Opublikowany',
      failed: 'Błąd',
      partially_published: 'Częściowo opublikowany'
    };

    return labels[status];
  }

  logout(): void {
    this.auth.logout().subscribe(() => void this.router.navigate(['/login']));
  }
}
