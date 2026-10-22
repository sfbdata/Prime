---
tipo: novidade
---
## Changelog
- Página da meta: bloco "Despacho" do desenho de 22/10 (spec `docs/specs/minhas-metas-esteira-2210.md`, Fase 2a). Natureza do despacho (Para ciência, Para providência, Para manifestação, Para conferência, Cumpra-se), prazo de cumprimento com padrão de 5 dias úteis pela regra do escritório (feriados nacionais, da UF e recesso) e "N dias úteis" ao lado, contador "N / 5.000" (limite conferido também no servidor), Ctrl+Enter e os botões "Despachar" e "Enviar para revisão". Título "Meta #N" com o número da meta no escritório.
- Linha do tempo: cada despacho como "DESPACHO N · natureza", com "Cumprir até", ciência automática ("Ciente · Fulano, data") quando o responsável ou o criador abre a meta, "Certificar cumprimento" (`tarefa_mensagem_certificar`) e a linha de registro "Registrado por NOME (Cargo) em DD/MM/AAAA às HH:MM · Cód. XXXX.XXXX" (decisão D1, nome e cargo do momento do despacho). Despacho registrado não pode ser editado.
- Conferência do código em `/tarefas/registro/{codigo}` (`tarefa_registro_validar`): só para quem é do mesmo escritório e está logado; mostra meta, autor, data e natureza, sem o texto.
- Página da meta alinhada ao desenho 04B: seta de voltar e trilha "Expediente / Pasta X / Meta", faixa "Aguardando despacho de" / "Aguardando revisão de" com os dias, painel de situação (Pendente, Aguardando revisão, Atrasada · N dia(s), Concluída) com "Etapa i/n · Nome", bloco "Detalhes da meta" (prazo com "Alterar", criada em, despachante), "Pasta: X · cliente", "DEMANDA" e a linha do tempo num formato único ("DESPACHO N", "MAIS RECENTE"). "Enviar para revisão" sem texto grava "Meta enviada para revisão do autor.". Despachar só com anexo e Cmd+Enter.
- Saem da página da meta "Excluir meta" e "Voltar à Pasta" (decisão do dono, o desenho não tem); excluir continua na aba Metas da pasta.
- "Precisa de atenção" em Minhas Metas abre a pasta no Expediente e deixa de fora metas de pasta excluída.
- `DiasUteisDoEscritorio` ganhou `somarUteis`, `uteisEntre` e `naoUteisEntre`.
- Migration `Version20261020150100`: colunas novas em `tarefa_mensagem` (natureza, prazo de cumprimento, ciência, certidão, código único por escritório e quem registrou).

## Para o cliente
- O despacho da meta ficou formal: escolha a natureza, o prazo para cumprir (já sugerido em 5 dias úteis) e cada despacho fica registrado com nome, cargo, data e um código que a equipe pode conferir. Quem recebe dá ciência ao abrir a meta, e dá para certificar o cumprimento direto no despacho.
