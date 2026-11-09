---
tipo: novidade
---
## Changelog
- Assinatura de documentos, etapa 1 (docs/specs/assinatura-documentos-etapa-1.md): domínio `App\AssinaturaEletronica` e tela `/assinatura` na moldura do sistema, desenhada pelo JS a partir de JSON (como a Agenda 1A). Aba Documentos com chips e contadores, busca, pastas, cores, seleção, menu de contexto, Lixeira, Duplicar e exclusão definitiva só de rascunho na Lixeira; aba Modelos com os 13 modelos prontos; assistente Novo documento em 4 passos (modelo pronto, PDF ou .docx, dados com prévia A4, signatários, mensagem e prazo) com Salvar rascunho; detalhe do documento em leitura com a trilha de auditoria.
- Trilha de auditoria só de acréscimo, encadeada pelo hash do evento anterior. PDF e logo enviados vão para a nova categoria de armazenamento `ASSINATURA_DOCUMENTO`, isolada por escritório, com purga, espelho e auditoria.
- Item "Assinatura" no menu lateral, chave `assinatura` no catálogo de Telas e recursos do Master e permissão `modules.assinatura_eletronica.view` (rótulo "Assinatura de documentos" no painel de Perfis).
- Migration Version20261109150000: tabelas do domínio; o recurso `assinatura` nasce desligado (só o Master vê) e a permissão é concedida aos perfis que já veem Pastas.

## Para o cliente
- Começamos a preparar a Assinatura de documentos: o escritório vai montar contratos e procurações a partir de modelos prontos ou de um PDF e mandar para o cliente assinar pelo celular. Por enquanto a tela fica desligada e só aparece para a equipe do BlueJus; avisaremos quando estiver liberada.
