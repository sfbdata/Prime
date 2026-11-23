---
tipo: novidade
---
## Changelog
- Backup: passa a ser só do dono do escritório (`Tenant::getCriadoPor()`). Regra única em `App\Backup\Service\DonoDoEscritorio`, usada em `PedirBackupUseCase`, `BaixarBackupUseCase` (403 para os demais, sem auditoria), `BackupController`, `BackupNoPerfilExtension` (a seção "Backup e Exportação de Dados" some) e `GerarBackupHandler` (quem deixou de ser dono tem o pedido marcado como `falhou`). Escritório sem criador: ninguém é dono.

## Para o cliente
- O Backup e Exportação de Dados fica disponível só para o dono do escritório, no Meu Perfil. As outras pessoas do escritório não veem mais essa seção e não conseguem pedir nem baixar o backup.
