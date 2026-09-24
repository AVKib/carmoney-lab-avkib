# Git worktrees

Вывод команды `git worktree list`:

```
C:/Workspace/SmartHorizon/Repositories/carmoney-lab-avkib                                480ebcd [d1/1.2.1-1.2.3-avkib]
C:/Workspace/SmartHorizon/Repositories/carmoney-lab-avkib/.kilo/worktrees/hallowed-farm  f47bf0c (detached HEAD)
C:/Workspace/SmartHorizon/Repositories/carmoney-lab-avkib/.kilo/worktrees/same-chocolate f47bf0c [same-chocolate]
```

- Основная копия репозитория — ветка `d1/1.2.1-1.2.3-avkib`, коммит `480ebcd`.
- Две дополнительные копии в `.kilo/worktrees/` (worktree `hallowed-farm` на detached
  HEAD и worktree `same-chocolate` на одноимённой ветке), обе на коммите `f47bf0c`.
