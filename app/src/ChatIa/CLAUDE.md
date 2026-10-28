# src/ChatIa/ — Chat I.A em página inteira

Spec: `docs/specs/chat-pagina-inteira.md` (decisões 1 a 16 e o contrato HTTP). Guia do dono: `docs/guias/chat-ia-dev.md`.
Namespace `App\ChatIa` · prefixo de rota `chat_ia_` · URL `/chat-ia` · módulo de permissão `inteligencia`
(`modules.inteligencia.view`). Reaproveita serviços de `Inteligencia` e `Central`; NÃO usa `central_conversa`.

## Regras inegociáveis

1. **Conversa é particular de quem a criou.** Toda consulta filtra por escritório E dono (`ConversaDeChatRepository::doDono`).
   Conversa alheia, de outro escritório ou inexistente é o MESMO 404 (`ConversaDeChatNaoEncontradaException`), nem o
   administrador lê. Isolamento entre usuários do mesmo escritório conta tanto quanto entre escritórios.
2. **Fora da auditoria.** As três entidades estão na lista de exceção do `AuditavelCoberturaTest` (o `audit_log` é lido por
   quem administra) e na purga do escritório (`PurgarEscritorioUseCase`, tabelas e arquivos). Texto de pergunta, resposta e
   anexo nunca vai para log, notificação nem audit.
3. **Guardas na ordem** (`AcessoAoChat` + `EnviarMensagemNoChatUseCase`): login, vínculo ativo, módulo da I.A, posse da
   conversa, provedor configurado (503 `sem_chave`), `DisponibilidadeDeInteligencia::para`, teto diário (429 `teto_diario`),
   pasta (aceite 409 `sem_aceite`, 404, segredo 409 `contexto_bloqueado`), anexos. Tudo recusa ANTES de abrir o SSE.
4. **O cliente nunca manda contexto.** A mensagem leva só `pastaId`; o servidor relê por id + escritório
   (`PastaDoContextoDaIa::liberar` + `MontadorDeContextoDaPasta`). Do que a pessoa escreve só saem controle e invisíveis;
   texto de arquivo e blocos da pasta são DADO (neutralizados, em bloco delimitado, `chat-livre-v1`). Bloco novo no prompt
   = nome novo em `NeutralizadorDeConteudo::DELIMITADORES`.
5. **Anexo**: tipo pelo CONTEÚDO (nunca extensão), limites da `RegraDaLeituraDeDocumentos` (3 por mensagem, PDF 10 MB, imagem
   3 MB, 20 MB somados); DOCX vira texto no servidor (`ExtratorDeTextoDocx`: zip bomb, sem DOCTYPE, 60 mil caracteres). Exige
   `aceiteCobreDocumentos`. Anexo é de quem o enviou e da conversa para a qual foi enviado. Arquivo só sai do disco depois do
   COMMIT (`RemocaoAposTransacao`); chave pela fábrica `ChavesDeChatIa`.
6. **Streaming**: `ProvedorDeLinguagemEmFluxo::transmitir` (gerador de trechos, devolve `UsoDaTransmissao`). O
   `TransmitirRespostaDoChatUseCase` grava a resposta com os tokens reais; cliente que aborta (`connection_aborted`) deixa o
   trecho parcial com `interrompida = true` (tokens estimados). Nunca logar corpo, texto nem contexto.
7. **Custo**: `CHAT_IA_TETO_TOKENS_RESPOSTA` (padrão 2000, `max_tokens` do pedido; o provedor soma a margem de raciocínio) e
   `CHAT_IA_TETO_TOKENS_DIA` (padrão 200000 por pessoa), em `services.yaml`. Limiter `inteligencia_solicitar` no envio.
8. **Título**: automático depois da primeira resposta (`completar`, 24 tokens, Haiku; fallback = início da pergunta); renomear
   à mão (`tituloManual`) vale sobre ele.
9. **Teste**: o provedor é o `ProvedorFalso` (`tests/Inteligencia/Support`), também em fluxo. Nenhuma chamada real à API.
10. **Tela** (`templates/chat_ia/pagina.html.twig`, `public/css/chat-ia.css`, `public/js/chat-ia.js`): layout próprio sem AdminLTE, textos no
    catálogo `translations/chat_ia/` e entregues ao JS em `data-textos`. O JS só usa `createElement`/`textContent` (o markdown da
    resposta tem renderizador próprio; links só http, https e mailto) e é guardado por `tests/ChatIa/Unit/ChatIaJsTest.php`.
