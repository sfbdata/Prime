# Conferência e abertura da votação

## Escopo autorizado

Primeira entrega da lista do dono, itens 1 e 3, incluindo o selo geral do item 2 por fazer parte do andamento mostrado. Depois seguem os urgentes 4 (XLSX/CSV/modelo) e 10 (cargo com candidaturas reais). Base e6d555a7b, após merge do comprovante por e-mail. Frente voto-publicacao-conferencia, banco isolado próprio.

## História do caso de uso

O gestor quer conferir a configuração antes de publicá-la e saber quando a votação efetivamente recebe votos. Precisa ter acesso ao Voto do próprio escritório. Ao escolher Publicar, recebe o resumo real e links Alterar. Pendências da validação existente são exibidas antes da confirmação. Confirmar publica e bloqueia as pautas, oferece abrir a votação agora e leva à escolha de pauta, convocação e prazo pelos controles existentes. Cancelar mantém o rascunho. Configuração alterada depois da revisão exige nova conferência. A publicação não abre pautas automaticamente.

## Contratos

- GET da conferência não muda a assembleia.
- POST de publicação exige CSRF e o código da configuração exibida. Sem código ou com código antigo, volta à conferência.
- Usar as invariantes da entidade na revisão e na publicação, sem duplicar regras.
- Resumo contém título, peso, aptos/inaptos, peso apto (frações somente quando aplicáveis), pautas, opções, vagas, dígitos, critério, base e quóruns de 1ª/2ª convocação.
- Convocação é escolhida na abertura de cada pauta. O sistema ainda não cadastra horários futuros ou quórum de instalação. Mostrar o dado disponível com esse significado.
- Selo e etapas derivados do estado atual. Rascunho antes de publicar. Votação aberta se alguma pauta está aberta. Encerrada quando todas estão encerradas. Publicada, aguardando abertura nos outros casos, inclusive pautas encerradas junto de pautas ainda aguardando.
- Oferta após publicar permite escolher a pauta usando os controles atuais, ou voltar ao painel.
- Sem migration, alteração de autorização ou mudança no registro do voto.
- Revisão, publicação e os seis comandos de edição/importação/remoção compartilham uma trava por escritório e assembleia, obtida antes de buscar a configuração e liberada depois do flush. A leitura do resumo fica na mesma reserva. A trava nomeada é voto_configuracao, no backend Doctrine já usado pelo projeto.

## Desenho e inventário

Fonte docs/design/voto/export-2026-11-11/consulta/voto.dc.html na raiz, linhas 55 a 57 (vPas) e 225 (edP7). As adições de etapas, selo e oferta de abertura seguem os pedidos explícitos do dono. Mesmos componentes e medidas do desenho.

| Região | Item | Valor/origem | Destino |
|---|---|---|---|
| Revisão | Quadro e linhas | borda 1px #d3dde4, vLin, flex gap10px, fonte13.5px | conferencia.html.twig |
| Revisão | Rótulo e valor | base150px #5b7383, valor bold flexível min-width0 | linhas de resumo |
| Revisão | Alterar | vB, aria-label Alterar seguido do campo | links para edição existente |
| Revisão | Pendência | #a3232b, fonte13.5px bold | erro antes do botão |
| Ações | Publicar votação | vB vP, bi-megaphone | confirmação POST |
| Etapas | vPas/vPasOk/vPasAt | cores e medidas das linhas55 a57 | partial compartilhado |
| Após publicação | Deseja abrir a votação agora? | pedido1, vC/vLin/vB existentes | oferta e escolha explícita da pauta |
| Responsivo | linhas e ações com quebra | flex-wrap, mínimo0 | CSS da tela |

## Plano de execução

1. Testes unitários e HTTP da revisão, configuração alterada e status mistos.
2. Reutilizar validação da entidade e implementar consulta da conferência.
3. Integrar entradas Publicar, resumo, etapas e oferta de abertura.
4. Testar Voto/arquitetura, qualidade, Twig/YAML/JS, revisar e abrir PR.

## Conferência final

| Item | Conferência |
|---|---|
| Quadro de revisão e links Alterar | ✓ Estrutura vLin, CSS e dados conferidos contra edP7 |
| Opções e regras reais de cada pauta | ✓ Teste HTTP do fluxo completo |
| Aptos e soma de frações aptas | ✓ Teste HTTP com 0,900000 |
| Pendência antes de bloquear | ✓ Publicação sem aptos desabilitada na revisão |
| GET preserva rascunho e POST exige conferência | ✓ Testes de consulta, confirmação e POST sem código |
| Configuração mudou depois da revisão | ✓ Hash recalculado no servidor e recusa no UseCase |
| Edições simultâneas | ✓ Oito UseCases com a mesma trava, teste de contenção e liberação após erro |
| Status com pautas mistas e todas encerradas | ✓ Teste HTTP de aguardando, aberta e encerrada |
| Abrir agora por pauta, convocação e prazo | ✓ Oferta e controles existentes preservados |
| Navegação e acesso entre escritórios | ✓ Sub-aba Conferência e testes HTTP de 404 |
| Responsivo e interação no navegador | Pendente do smoke do Samuel |

Validação local: 662 testes de Voto e arquitetura, 5.649 asserções. PHPStan nível 5 e estilo dos seis PHP novos aprovados. 28 Twig e dois YAML aprovados. Após a última correção de 404 no POST editar, os testes direcionados da gestão da assembleia e da trava foram repetidos.

Revisão read-only e re-revisão aprovadas. Os achados corrigidos foram a corrida entre revisão/publicação e edição, a leitura antecipada no POST de editar e a manutenção da resposta 404 para outro escritório. A prova automática de trava usa o store em memória. Não houve teste de duas conexões reais simultâneas nem smoke de navegador pelo agente.

Os itens 4 e 10 seguem como próximos urgentes. Esta entrega mantém a validação atual das opções. A distinção explícita entre pauta e cargo, com exigência de candidaturas reais, será tratada no item 10.
