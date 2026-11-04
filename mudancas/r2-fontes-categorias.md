---
tipo: alteracao
---
## Changelog
- Espelho R2 (etapa 6): a reconciliação, a carga do acervo e a liberação de disco passam a conhecer as referências de seis categorias que ainda não eram espelhadas: `pasta_foto` (`pasta.foto`), `tenant_logo` (`tenant.logo_chave`), `chat_ia_anexo` (`chat_ia_anexo.chave`), `inteligencia_documento_juridico` (`inteligencia_documento_juridico_versao.nome_arquivo`), `recompensa_comprovante` (`lancamento_de_recompensa.comprovante_chave`) e `blog_imagem` (`artigo_blog_imagem.chave`). As quatro últimas são globais. Fecha a pendência da P4 do blog em `FontesDeReferencia`.
- Nenhuma categoria é ligada por este PR: elas continuam desligadas até entrarem em `ARMAZENAMENTO_ESPELHO_CATEGORIAS`.

## Para o cliente
- As fotos das pastas, a logo do escritório, os anexos do chat da IA e as imagens dos artigos já podem ganhar uma cópia de segurança na nuvem, como os documentos. A cópia passa a ser feita quando cada uma for ativada.
