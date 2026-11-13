# Comprovante de participação por e-mail

## Estado

Implementação retomada em 13/11 após publicação da v2.50.0. PDF e cota confirmados no handoff canônico.
Frente: `voto-comprovante-email`, base atualizada para `df51c16d0` (v2.50.0 publicada).

## Fonte e apresentação

O desenho `docs/design/voto/export-2026-11-11/consulta/voto.dc.html`, linha 152, contém o fluxo no cartão já votado e habilitado: botão Comprovante em imagem, campo editável E-mail para receber o comprovante e botão Enviar por e-mail.
O campo começa com o e-mail da identificação. A função `cpMail`, linha 353, nunca inclui a escolha. O `mailto:` é o mecanismo do protótipo; `ESPEC_VOTO.md`, linha 242, exige servidor com anexo em produção.
O desenho está disponível na raiz do repositório, mas é um arquivo local não rastreado e não veio para a worktree.

## História e contrato

1. Quem: eleitor com o link pessoal válido da unidade, apto e com participação registrada na pauta.
2. Objetivo: guardar por e-mail a prova de participação, sem revelar o voto.
3. Pré-condições: módulo liberado para o escritório do link, participação da própria unidade na pauta da própria assembleia, CSRF e destinatário válido.
4. Fluxo: conferir acesso, carregar participação, recalcular o BJV existente, gerar anexo com dados do servidor e enviar ao endereço informado.
5. Alternativas: link inválido, ausência de participação, unidade inapta, endereço inválido, limite excedido e falha do correio. Falha de envio permite tentar novamente e nunca altera o voto.
6. Pós-condição: correio aceita o comprovante para envio; participação e contadores permanecem iguais.
7. Restrições: nunca aceitar do navegador código, data, nome ou imagem do comprovante como fonte confiável. Nunca consultar opções, números nulos ou abstenção para compor o e-mail. Nunca anexar o link pessoal nem alterar o HMAC existente.

## Decisão confirmada

Samuel confirmou anexo PDF, destinatário editável após votar e três tentativas por hora por unidade. Cada destinatário consome uma tentativa, inclusive falha de correio. A cota soma todas as pautas da unidade. Janela móvel exata de 3600 segundos, cache e trava compartilhada antes de chamar o correio.

## Sequência de implementação

1. Completar o inventário do trecho do desenho e os testes.
2. Escrever testes de UseCase e controller para autorização, participação, destinatário, limite e falha de correio.
3. Implementar envio e componente no cartão, com dados gerados exclusivamente no servidor.
4. Conferir testes do Voto, arquitetura, Twig, JS e qualidade. Smoke visual pelo Samuel.

Sem migration. PDF gerado em memória pelo GeradorDePdf existente. Alteração compartilhada prevista em config/packages/lock.yaml para a trava exclusiva da cota. Outros PRs abertos não envolvem Voto.

## Inventário do componente

| Item | Desenho | Implementação prevista |
|---|---|---|
| Estado | e.temCp, participação própria registrada e habilitada | cartão com votouEm e unidade apta |
| Linha | display flex, gap 8px, flex-wrap wrap | vcComprovante |
| Imagem | vB, bi-image, Comprovante em imagem | download PNG também no cartão, além do FIM existente |
| E-mail | vF, flex 1 1 200px, width auto, type email | campo editável iniciado com cadastro da unidade |
| Acessibilidade | placeholder e aria-label E-mail para receber o comprovante | ambos preservados |
| Enviar | vB, bi-envelope, Enviar por e-mail | POST com CSRF, PRG e melhoria por fetch |
| Erro | Informe um e-mail válido., toast vermelho | validação no servidor e feedback acessível |
| Sigilo | somente participação | assembleia, pauta, unidade, hora e BJV do servidor |
| PDF | anexo confirmado por Samuel | HTML escapado do servidor, sem recursos externos |

O protótipo contém nome e localização de acesso. A unidade atual oferece somente identificação/bloco no comprovante existente. Esta frente preserva o contrato existente da participação e não implementa coleta de localização. Conferência final do inventário será registrada após os testes.

## Conferência final de 13/11

| Item | Resultado |
|---|---|
| Cartão da pauta já votada e unidade apta | ✓ Teste DOM por filho direto |
| Imagem, campo editável e botão com textos/ícones do desenho | ✓ Conferidos contra a linha 152 e CSS existente |
| Flex, quebra de linha, gap 8px e campo com base 200px | ✓ CSS do componente |
| Acessibilidade do campo e resposta | ✓ aria-label, aria-live, aria-busy e bloqueio de clique repetido |
| Dados do servidor e sigilo | ✓ DTO contém somente assembleia, pauta, unidade, hora e BJV |
| PDF anexo | ✓ Geração real no teste de correio e inspeção do PDF fictício renderizado |
| Cota de três tentativas por hora | ✓ Testes de limite, janela móvel, destinatários, pautas e falha de correio |
| Acesso público, CSRF e outro escritório | ✓ Testes HTTP, inclusive usuário logado sem escritório e sem aceite |
| Terminal encerrado e cabeçalhos | ✓ Nova rota incluída nas guardas existentes |
| Envio do protótipo por mailto | ⚠ Servidor com PDF, conforme decisão do Samuel |
| Smoke visual no navegador e entrega real na caixa de entrada | Pendente do Samuel |

Validação final local: 656 testes Voto + arquitetura, 5.625 asserções. PHPStan nível 5 e CS-Fixer dos dez PHP novos aprovados. 28 Twig e dois YAML aprovados. JavaScript de comprovante, nulo e pautas aprovado, além da verificação de sintaxe dos arquivos alterados. Registro de mudança e git diff --check aprovados.

Revisão read-only encontrou a ausência da nova rota nas exceções de TermoAceiteListener e TenantContextValidatorListener. Corrigido nas duas listas, acrescentado teste HTTP de regressão e re-revisão aprovada. Sem migration nesta entrega. Foram aplicadas apenas as migrations existentes no banco isolado saas_testvoto-comprovante-email para alcançar a base publicada.

O layout PDF foi definido nesta implementação com a tipografia e as cores já usadas pelo comprovante. Não existe desenho específico do anexo PDF. A entrega real de e-mail usa o transporte configurado do sistema e não foi acionada contra destinatário externo nos testes do agente.
