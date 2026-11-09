# Ponto Eletrônico pelo desenho 16 (VS 08.11.26): fase 1, análise

**Risco:** ALTO (ponto eletrônico) · **Origem:** desenho "16 - Ponto Eletronico - VS 08.11.26", artefato
https://claude.ai/code/artifact/3c9fe618-65e4-457f-8d3d-c31bb68901c8, cópia em `docs/design/redesign-08-11-26/`
(com `ESPEC_PONTO_ELETRONICO.md` e o inventário `inventario-ponto-0811.md`) · **Data:** 09/11/2026 ·
**Estado:** decidido em 09/11/2026 (`decisoes-desenhos-0911.md`, na pasta bluejus-coordenacao); em implementação por
etapas.

**Decisão já tomada (Coordenador, 09/11/2026; não é pergunta):** o cabeçalho e o menu do topo do desenho são a moldura do
sistema (`base.html.twig`), como em `docs/specs/agenda-1a.md` e `docs/specs/meu-escritorio.md`. A tela implementa só o
conteúdo; o link do Ponto entra no menu que já existe. As camadas globais que o desenho carrega (inventário §2.1 e §2.20:
dicas, idioma, tema comemorativo, aviso de espaço do plano, lembretes da Agenda, "Indicar o BlueJus", Central de
Comunicação, medição de erros, guarda das telas do Master) também são da moldura.

Fontes desta spec: inventário do desenho (`docs/design/redesign-08-11-26/inventario-ponto-0811.md`, citado como
"inventário §N"), mapa do Ponto que existe no master em 09/11 (commit `940e82dfd`), regras do dono para o sistema real
(`ESPEC_PONTO_ELETRONICO.md`, citado como "ESPEC") e a frente recém-entregue `docs/specs/ponto-jornada-vigencia.md` (§9 e §10).

## 1. Resumo

1. O desenho troca o conteúdo da `/ponto`: duas colunas, cartão "Registrar Ponto" novo, Folha listrada e um cartão com 7 abas.
2. Cabeçalho, menu do topo e camadas globais ficam a moldura do sistema (decisão do Coordenador, acima); o Ponto é só conteúdo.
3. Boa parte é aparência sobre o motor de hoje (bloco 1 da seção 5); o resto é motor novo (ajuste, trilha, comprovante, aceite, lotes, fila, facial).
4. Treze itens batem de frente com decisões do dono já registradas (cerca, editar batida, banco anual, botão cinza, validações...): eram (PERGUNTA) e foram decididas em 09/11/2026 (P7 a, P10 b e P11 b pelo dono; as outras pelas regras do dono).
5. Funções do sistema que o desenho não mostra (ficha do colaborador, Home Office, sedes com mapa, XLSX, alertas) ficam, cada uma com o seu item.
6. Facial, comprovante com valor legal, fila sem internet e o que depende de lei ou fornecedor vão por último, atrás das chaves do Master.
7. Nada toca a frente jornada-vigencia sem aviso: cada etapa diz quais arquivos dela encosta.

## 2. (a) Antes e depois, item a item (decidido em 09/11/2026)

Cada item traz a decisão registrada em `decisoes-desenhos-0911.md` (pasta bluejus-coordenacao). P7 (a), P10 (b) e P11 (b)
são respostas do próprio dono; as outras seguem as regras do dono (fiel ao desenho; função do sistema não sai; além do
desenho só se necessário).
"Decisão: aceito" quer dizer: entra como o desenho mostra. "Decisão: não" quer dizer: não entra.
"Fica" quer dizer: a função existe hoje, o desenho não mostra, e ela continua onde está, no estilo do desenho.
(PERGUNTA) quer dizer: o desenho ia contra uma decisão do dono já registrada; a pergunta fica para constar, com a opção
escolhida.
"(decidido)" quer dizer: já resolvido pelo Coordenador antes; está aqui só para constar.
"(contra a recomendação acima)" marca os itens em que a decisão foi diferente do que esta spec recomendava.

### Registrar ponto

- **P1.** Página em duas colunas. Hoje: cartões um embaixo do outro, no estilo antigo do sistema. | No desenho: título grande "Ponto Eletrônico"; à esquerda o cartão "Registrar Ponto"; à direita a "Folha de Ponto" e um cartão com abas; cores, bordas e cantos do desenho; no celular vira uma coluna só. | Decisão: aceito.
- **P2.** Banco de horas no topo do cartão. Hoje: saldo do ano no cabeçalho do cartão, verde ou vermelho. | No desenho: barra azul com "Banco de horas" no canto direito e um triângulo amarelo quando o saldo passa do limite (o número depende do P51). | Decisão: aceito. O número segue o P51 (lotes).
- **P3.** Situação do dia. Hoje: não aparece. | No desenho: frase acima do relógio: "Aguardando entrada", "Em jornada", "Em repouso" ou "Jornada encerrada". | Decisão: aceito.
- **P4.** Relógio do dia. Hoje: relógio do tempo trabalhado hoje. | No desenho: o mesmo relógio, grande, com horas, minutos e segundos. | Decisão: aceito.
- **P5.** Hora oficial. Hoje: não aparece. | No desenho: linha "Hora oficial 14:32:10 · segunda-feira, 09/11/2026", com a hora do servidor (não a do celular). | Decisão: aceito. Conferir o NTP da VPS é do dono (seção 10).
- **P6.** Escolha do tipo. Hoje: lista para escolher (Entrada, Repouso, Retorno, Saída), já no próximo tipo. | No desenho: 4 botões lado a lado, o próximo já marcado, dá para escolher outro; cada botão mostra a hora já batida ou "--:--:--". | Decisão: aceito.
- **P7. (PERGUNTA)** Tipo já batido e dia completo. Hoje: dá para bater o mesmo tipo de novo (a folha usa a primeira entrada e a última saída) e o botão nunca fica cinza, sempre responde ao toque. | No desenho: o tipo já batido fica verde e não aceita clique; com os 4 feitos o botão vira "Jornada de hoje concluída", cinza e sem clique. | Origem: specs ponto-batida-que-responde-e-conta-certa (o botão responde ao toque) e ponto-folha-uma-batida-por-tipo (§7.1). | Opções: (a) travar como o desenho; (b) mostrar o verde e a hora, mas deixar bater de novo, como hoje. Recomendo (b). | Decisão: (a), resposta do dono: travar como o desenho.
- **P8.** Batidas de hoje. Hoje: lista "Suas batidas de hoje" abaixo do botão, com o selo "N registros" quando o mesmo tipo foi batido mais de uma vez. | No desenho: a hora fica dentro de cada botão do tipo e a lista sai. Se aceito, o aviso de batida repetida (que o sistema tem) precisa de lugar: recomendo no próprio botão do tipo. | Decisão: aceito. O aviso de batida repetida vai para o botão do tipo.
- **P9.** Caixa da localização. Hoje: caixa acima do botão com "GPS: 12m", impreciso, fora da área (sede e distância), "Home Office hoje" e erro. | No desenho: caixa com faixa colorida à esquerda e botão de atualizar: "Obtendo a sua localização...", "Localização confirmada. Precisão de N m.", "GPS impreciso (N m). O sistema ainda validará se você está dentro do raio de alguma sede.". Se aceito, os estados que só o sistema tem (fora da área, Home Office hoje, erro) ganham o mesmo molde. | Decisão: aceito. Fora da área, Home Office e erro ganham o molde do desenho.
- **P10. (PERGUNTA)** Erro de localização. Hoje: texto para cada tipo de erro, botões "Permitir localização" e "Tentar de novo" e passo a passo por navegador e celular, sem travar o botão. | No desenho: o erro só aparece depois do toque, na tela vermelha: "Não foi possível obter a sua localização" e "Ative o GPS do aparelho, permita a localização no navegador e tente de novo.". | Origem: spec ponto-gps-erro-que-explica. | Opções: (a) só o texto do desenho; (b) a tela vermelha do desenho e, na caixa da localização, o passo a passo de hoje. Recomendo (b). | Decisão: (b), resposta do dono: a tela vermelha do desenho e, na caixa da localização, o passo a passo de hoje.
- **P11. (PERGUNTA)** Resposta depois do toque. Hoje: caixa abaixo do botão (registrado, recusado, sem rede, sem confirmação) e o botão "Atualizar e conferir minhas batidas". | No desenho: tela cheia verde ou vermelha (P79) ou aviso flutuante no rodapé (P80); não há caixa abaixo do botão. | Origem: decisão do dono no teste de 14/09, "duas caixas com donos diferentes" (spec ponto-batida-que-responde-e-conta-certa). | Opções: (a) só o desenho; (b) o desenho e, nos casos "sem rede" e "sem confirmação", a caixa de hoje com o botão de conferir. Recomendo (b). | Decisão: (b), resposta do dono: o desenho e, nos casos "sem rede" e "sem confirmação", a caixa de hoje com o botão de conferir.
- **P12.** Aviso do intervalo e da saída. Hoje: contador do repouso com aviso do mínimo e "Previsão de saída: HH:MM". | No desenho: caixa colorida "Intervalo em andamento. O mínimo de 1h se completa em HH:MM.", "Intervalo mínimo de 1h cumprido.", "Saída prevista às HH:MM." e, passada a hora, "Passou da saída prevista (HH:MM). Não esqueça de registrar a saída.". Se aceito, o mínimo do intervalo vem da configuração do escritório (o desenho fixa 1h). | Decisão: aceito. O mínimo vem da Jornada do escritório (o desenho fixa 1h).
- **P13.** Fica (o desenho não mostra): "X a mais hoje" (horas a mais depois de cumprir o dia). Fica onde está? | Decisão: fica.
- **P14.** Última pendência no cartão. Hoje: não aparece no cartão (aparece na lista de pendências e no cartão diário). | No desenho: antes da entrada do dia, faixa amarela "Última pendência: dd/mm/aaaa, falta a saída." com o botão "Regularizar", que abre o registro de esquecimento. | Decisão: aceito.
- **P15.** Botão principal. Hoje: "Bater Ponto" com ícone de digital e o tipo no rótulo. | No desenho: botão azul alto "Bater Ponto: Entrada" com a digital desenhada; com a facial obrigatória vira "Bater Ponto com facial: Entrada". | Decisão: aceito.
- **P16.** Rodapé do cartão. Hoje: botão "Justificar Falta" e, em outro cartão, "Jornada semanal: 44h00m". | No desenho: uma linha com "Justificar falta", o botão de som e "Jornada semanal 44h00m" (o número vem da jornada da pessoa, ver seção 4). | Decisão: aceito. A carga vem da jornada da pessoa (D1).
- **P17.** Fica (o desenho não mostra): selo "Personalizada" ou "Padrão do escritório" junto da jornada semanal. Fica? | Decisão: fica.
- **P18. (PERGUNTA)** Fora do raio da sede. Hoje: a batida é recusada ("Você está fora da área permitida das sedes"), na tela e no servidor. | No desenho e na regra nova do dono (ESPEC): tela vermelha "Você está fora do raio permitido" com o botão "Registrar como trabalho externo", que pede "Local e motivo", registra a batida e manda para a análise do gestor. | Origem: decisão do dono de 11/09/2026, "a cerca continua bloqueando" (spec ponto-batida-nao-se-perde-no-navegador). | Opções: (a) seguir o desenho; (b) manter o bloqueio. Recomendo (a): a regra nova é do próprio dono e é mais recente. | Decisão: (a). O desenho mostra o trabalho externo no lugar da recusa.
- **P19.** Fica (o desenho não mostra): Home Office (no dia liberado, bate sem localização e a folha mostra o selo). Fica? | Decisão: fica.
- **P20. (PERGUNTA)** Batida sem internet. Hoje: não há fila; a hora é a de chegada no servidor e o envio com mais de 60 segundos de atraso não sai (a pessoa bate de novo). | No desenho: a batida fica guardada no aparelho, aparece "Sem internet: N batida(s) aguardando envio." e ela vai sozinha quando a internet volta. | Origem: spec ponto-batida-nao-se-perde-no-navegador (usar a hora do toque ficou como "decisão do dono para frente futura"). | Opções: (a) aceitar a fila: vale a hora do aparelho, as duas horas ficam guardadas e a batida aparece marcada "enviada sem internet" para o gestor; (b) sem fila, como hoje. Recomendo (a), numa das últimas etapas. | Decisão: (a). O desenho mostra a fila; as duas horas ficam guardadas.
- **P21. (PERGUNTA)** Intervalo curto e descanso entre jornadas. Hoje: quando o escritório liga as validações, o retorno com repouso menor que o mínimo e a entrada antes do descanso entre jornadas são recusados. | No desenho e na regra nova do dono: janela "Antes de registrar" com o aviso e o botão "Registrar assim mesmo" ("O alerta não impede o registro: a folha deve refletir o horário real."); a folha marca "não conforme"; também avisa o 7º dia seguido sem descanso. | Origem: specs ponto-esquecimento-nao-trava (R4: "quando a saída foi batida normalmente, a interjornada continua como hoje") e ponto-por-escritorio. | Opções: (a) avisar sem recusar; (b) manter a recusa. Recomendo (a). | Decisão: (a). O desenho avisa sem recusar; os mínimos vêm da Jornada do escritório.

### Folha

- **P22.** Barra da folha. Hoje: cartão "Folha de Ponto - MM/AAAA" com filtro do mês e o botão "Visualizar / Exportar". | No desenho: barra azul com o mês para escolher, "Baixar PDF" e, quando ligado, "Assinar folha" e o selo "Assinada em dd/mm/aaaa". | Decisão: aceito.
- **P23.** Meses que o colaborador vê. Hoje: só o mês atual e o anterior. | No desenho: todos os meses desde o início do uso. | Decisão: aceito.
- **P24.** Cores das linhas. Hoje: fim de semana em cinza e feriado em amarelo. | No desenho: linhas listradas e, ao passar o mouse, a linha fica azul com letra branca. | Decisão: aceito.
- **P25.** Quais dias aparecem. Hoje: todos os dias do mês. | No desenho: só os dias com batida (e feriado com horas). Atenção: no desenho o dia sem batida some da tela, mas desconta do saldo. Recomendo "não" (continuar com todos os dias). | Decisão: aceito (contra a recomendação acima). O filtro do desenho é explícito; a falta continua descontando do saldo.
- **P26.** Folha no celular. Hoje: a tabela rola para o lado. | No desenho: até 760 px de largura, cada dia vira um cartão com o nome de cada campo. | Decisão: aceito.
- **P27.** Selos do dia. Hoje: "A conferir", "Registro incompleto", "Home Office". | No desenho: "Fora de sequência" (vermelho, quando um horário é menor que o anterior), "Feriado", "Em andamento" (hoje) e "Registro incompleto". | Decisão: aceito.
- **P28.** Fica (o desenho não mostra): selos "A conferir" (dia com batidas fora da conta) e "Home Office". Ficam? | Decisão: fica.
- **P29.** Marca "aj" na batida corrigida. Hoje: não há (a batida corrigida pelo gestor é trocada). | No desenho: "aj" ao lado da hora, com a dica "Ajustado por NOME em dd/mm/aaaa. Registro original preservado." (depende do P65). | Decisão: aceito. Depende do P65 (a).
- **P30. (PERGUNTA)** "+" do esquecimento. Hoje: "+" na batida que falta, só em dia útil passado que tem alguma batida; ampulheta quando já pediu. | No desenho: "+" em qualquer dia passado da folha; hoje e feriado mostram um traço. | Origem: decisão do dono de 28/07 (spec ponto-atalho-esquecimento-registro). | Opções: (a) desenho; (b) regra de hoje com o "+" no visual do desenho e a ampulheta. Recomendo (b). | Decisão: (a) (contra a recomendação acima). "+" como o desenho; "Em análise" com ampulheta fica na coluna Justificativa. Correção de 09/11/2026 (pedido do Samuel, aprovado pelo Coordenador): o dia de hoje também tem o "+". Função do sistema que volta (regra 2): antes da E2 o "+" já valia hoje (`chaveDia <= dataHoje`; o "Hoje" acima dizia "dia passado" por engano), e a spec ponto-atalho-esquecimento-registro diz que "esqueci de bater a entrada hoje de manhã" é o caso mais comum do atalho. Feriado continua com o traço.
- **P31.** Coluna Justificativa. Hoje: Abonada, Pendente, Rejeitada, Não abonada, com o tipo e o horário do abono parcial. | No desenho: selos coloridos "Abonada" (verde), "Em análise" (azul) e "Recusada" (vermelho). Se aceito, "Não abonada" e o horário do abono parcial ficam. | Decisão: aceito. "Não abonada" e o horário do abono parcial ficam.
- **P32.** Fica (o desenho não mostra): rodapé "Saldo do mês / Horas pagas / Total do mês". Fica? | Decisão: fica.
- **P33.** Assinar a folha. Hoje: não existe (o PDF tem linhas para assinar no papel). | No desenho: no mês já fechado, botão "Assinar folha" com "Concordo: os registros representam fielmente minha jornada" ou "Discordo de parte dos registros (ressalva)" e o texto da ressalva; depois, selo "Assinada em" ou "Ressalva em" e carimbo no PDF. Não dá para desfazer. | Decisão: aceito. O texto do aceite vai ao pacote jurídico, sem travar.
- **P34.** PDF novo da folha. Hoje: PDF em texto, com empregador, empregado, totais e linhas de assinatura em papel. | No desenho: PDF de uma página em imagem (texto não selecionável) com o código BJF, QR Code, carimbo do aceite, quadro de intervalos e "Base Legal". | Decisão: aceito. PDF em imagem, gerado no servidor (D9 e D17).
- **P35. (PERGUNTA)** PDF com pendência. Hoje: PDF e XLSX nunca mostram "a conferir", "incompleto" nem "registro em outro escritório". | No desenho: o PDF escreve "Registro incompleto", "Horário fora de sequência: revisar" e "Falta: em análise" nas observações do dia. | Origem: specs ponto-abono-nao-perdoa-jornada e ponto-esquecimento-nao-trava (R8). | Opções: (a) desenho; (b) PDF sem pendência, como hoje. Recomendo (b). | Decisão: (a) (contra a recomendação acima). O PDF escreve as pendências do dia, como o desenho; "registro em outro escritório" continua fora.
- **P36.** Frases fixas no PDF. Hoje: não há. | No desenho: "OBSERVAÇÕES GERAIS" com "As horas extras foram autorizadas previamente pela gestão e serão compensadas conforme acordo de banco de horas.", "Feriados trabalhados serão compensados." e "Registros realizados pelo sistema eletrônico de ponto BlueJus.". Recomendo "não" para as duas primeiras (afirmam algo que o sistema não sabe). | Decisão: aceito (contra a recomendação acima). As três frases são do desenho e ficam; as duas primeiras vão anotadas no pacote jurídico.

### Pendências

- **P37.** Pendências viram aba. Hoje: cartão "Pendências de registro" (últimos 30 dias, todos os escritórios). | No desenho: aba "Pendências", a primeira, aberta ao entrar, com o número no título, o texto "Dias dos últimos 30 em que faltou uma batida. Elas não impedem o registro do ponto: envie o registro de esquecimento para regularizar." e o botão "Enviar registro de esquecimento". | Decisão: aceito.
- **P38.** Fica (o desenho não mostra): link "Trocar de escritório" na pendência de outro escritório. Fica? | Decisão: fica.

### Justificativas

- **P39.** "Minhas Justificativas" vira aba. Hoje: cartão com Data(s), Tipo, Status, Atestado, Obs. da Análise, Analisado em. | No desenho: aba com as mesmas colunas, selos coloridos, cadeado "Análise concluída: não pode ser alterada." e só as justificativas do mês escolhido na Folha. | Decisão: aceito.
- **P40.** Nomes das situações. Hoje: Pendente, Abonado, Rejeitado. | No desenho: Em análise, Abonado, Recusado. Se aceito, muda em todas as telas, inclusive na ficha do gestor e nos avisos do sininho. | Decisão: aceito. O rótulo é um só e muda junto em todas as telas.
- **P41.** Excluir a justificativa em análise. Hoje: o colaborador só edita. | No desenho: lixeira "Excluir enquanto não analisada" (no desenho apaga sem perguntar; ver seção 4). | Decisão: aceito. Com confirmação antes de apagar (D4).
- **P42.** Fica (o desenho não mostra): editar a justificativa em análise (tipo, horário, troca do atestado do lote). Fica? | Decisão: fica.
- **P43. (PERGUNTA)** Gestor abonar a própria justificativa. Hoje: o gestor analisa na ficha de cada colaborador; analisar a própria está em aberto para o dono. | No desenho: na aba "Minhas Justificativas", o usuário primário e o Master veem "Abonar" e "Recusar" nas próprias justificativas. | Origem: spec ponto-edicao-justificativa-analisada (§6, em aberto). | Opções: (a) desenho; (b) ninguém analisa a própria; os botões aparecem só na aba Equipe, para as dos outros. Recomendo (b). | Decisão: (a) (contra a recomendação acima). O primário e o Master veem "Abonar" e "Recusar" nas próprias justificativas, como o desenho. A mesma regra vale na ficha do colaborador (E3): na própria ficha, quem gere pelo perfil e não é primário nem Master não abona, não recusa, não volta para análise, não usa "Aprovar todos" nem "Justificar Falta" (o lançamento do gestor já sai abonado); os botões não aparecem, uma linha diz o porquê e o servidor recusa com 403. É a exceção ao "fica" do P59 para a própria ficha; a análise da justificativa de outra pessoa segue como antes.
- **P44.** Janela "Justificar falta". Hoje: janela com calendário de vários dias, tipo, abono parcial, esquecimento e anexo. | No desenho: janela nova (barra azul, botões "Cancelar" e "Enviar") com data única, motivo e atestado opcional. Se aceito, entra a moldura do desenho e os campos de hoje ficam (P45). | Decisão: aceito. Os campos de hoje entram na moldura nova (P45).
- **P45.** Fica (o desenho não mostra): vários dias de uma vez, os 45 tipos em 6 grupos, abono parcial com horário e anexo PDF, JPG ou PNG até 10 MB. Ficam? | Decisão: fica.
- **P46.** Janela "Registro de esquecimento". Hoje: janela com o tipo e o horário. | No desenho: "dd/mm/aaaa · Saída", horário com segundos, motivo obrigatório e o aviso "Enviado para aprovação do gestor.". | Decisão: aceito.
- **P47.** Fica (o desenho não mostra): janela "Editar justificativa" (ganha a moldura do desenho). Fica? | Decisão: fica.

### Comprovantes

- **P48.** Aba Comprovantes. Hoje: não existe. | No desenho: lista de cada batida com data e hora, tipo, método ("Facial" ou "Sistema"), código "BJP-XXXX-XXXX-XXXX" e o botão "Baixar". | Decisão: aceito. Criar o segredo do HMAC em produção é do dono (seção 10).
- **P49.** Comprovante em imagem. Hoje: não existe. | No desenho: imagem grande com trabalhador, empregador e CNPJ, local, método, número sequencial (NSR), código, QR Code, marca d'água e padrões de segurança. | Decisão: aceito.
- **P50.** Validar comprovante. Hoje: não existe. | No desenho: botão "Validar código", janela que responde "Comprovante autêntico." ou "Código não localizado.", e uma página aberta (sem login) para quem ler o QR Code. | Decisão: aceito. Nome inteiro, como o desenho mostra; sem CPF nem localização.

### Banco de horas

- **P51. (PERGUNTA)** Banco por lotes. Hoje: saldo do ano, que zera em 1º de janeiro. | No desenho: aba "Banco de Horas" com "SALDO ATUAL", "LIMITE CONFIGURADO" (120h), "PRAZO DE COMPENSAÇÃO" (6 meses) e uma tabela de lotes por mês com vencimento e situação ("No prazo", "Vence em N dias", "Vencido", "Compensado"); as horas negativas abatem primeiro os créditos mais antigos. | Origem: decisão do dono de 31/07/2026 (spec ponto-horas-pagas-banco-de-horas). | Opções: (a) lotes do desenho, sem zerar em janeiro; (b) a aba mostra o saldo do ano de hoje, sem lotes. Recomendo levar ao dono antes (muda o saldo de todo mundo). | Decisão: (a) (contra a recomendação acima). Lotes do desenho, sem zerar em janeiro; as horas pagas seguem no saldo.
- **P52.** Aviso acima do limite. Hoje: não há limite. | No desenho: faixa amarela "Saldo acima do limite configurado. Planeje a compensação para evitar passivo." e o triângulo no cartão. | Decisão: aceito.
- **P53.** Fica (o desenho não mostra): horas pagas lançadas pelo gestor entram no saldo. Fica? | Decisão: fica.

### Equipe

- **P54.** Aba Equipe. Hoje: não há visão da equipe; o gestor abre a ficha de cada um. | No desenho: tabela com Colaborador, Situação ("Em jornada", "Em intervalo", "Sem registro", "Encerrada"), Entrada, Último registro, Banco, Pendências e Escala; a primeira linha é a própria pessoa ("Você"). | Decisão: aceito.
- **P55.** Números da equipe. Hoje: não há. | No desenho: cinco quadros: "Em jornada", "Em intervalo", "Sem registro", "Pendências" e "A analisar". | Decisão: aceito.
- **P56. (PERGUNTA)** Escala na aba Equipe. Hoje: jornada por dias e horários, com "Vale a partir de" e histórico (entregue em 07/11). | No desenho: lista com 4 escalas fixas (44h de segunda a sábado, 44h de segunda a sexta, 40h, 30h) que troca a escala da pessoa ali mesmo. | Origem: spec ponto-jornada-vigencia (§8 e §10). | Opções: (a) desenho; (b) a coluna só mostra a jornada atual, com link para a ficha. Recomendo (b). | Decisão: (a) (contra a recomendação acima). A coluna mostra a jornada atual; trocar grava vigência nova a partir de hoje.
- **P57.** Abonar várias de uma vez. Hoje: "Aprovar todos" só dentro de um lote, na ficha de cada pessoa. | No desenho: "Justificativas a analisar (N)" com caixas de marcar e o botão "Abonar selecionadas" (não há recusar em lote). | Decisão: aceito.
- **P58.** Quem vê Equipe, Auditoria e Configurações. Hoje: quem tem no perfil a permissão de gerir usuários ou o ponto (não só o primário), mais o primário e o Master. | No desenho: só o usuário primário e o Master. Recomendo "não" (seguir as permissões de hoje). | Decisão: não. Quem gere o ponto pelo perfil continua vendo; o desenho não conhece perfis.
- **P59.** Fica (o desenho não mostra): ficha do colaborador, aba "Justificativas" (lotes, aprovar com observação, rejeitar, aprovar todos, reverter, lançar justificativa já abonada). Fica? | Decisão: fica.
- **P60.** Fica (o desenho não mostra): ficha do colaborador, aba "Batidas de Ponto" (folha de qualquer mês, adicionar batida, exportar, "Fora da conta", sinal de registro em outro escritório). Fica? | Decisão: fica.
- **P61.** Fica (o desenho não mostra): ficha do colaborador, aba "Jornada Personalizada" (chave "Registra ponto eletrônico", "Vale a partir de", histórico, "Limpar escala"). Fica? | Decisão: fica.
- **P62.** Fica (o desenho não mostra): ficha do colaborador, aba "Home Office". Fica? | Decisão: fica.
- **P63.** Fica (o desenho não mostra): ficha do colaborador, aba "Horas pagas". Fica? | Decisão: fica.

### Auditoria

- **P64.** Aba Auditoria. Hoje: o sistema guarda o histórico, mas não há aba no Ponto. | No desenho: aba com "Cadeia de eventos íntegra: cada evento é selado com o anterior. Registros originais nunca são sobrescritos." e a lista Data e hora, Autor, Ação, Detalhe e Selo. | Decisão: aceito.
- **P65. (PERGUNTA)** Corrigir batida só com ajuste. Hoje: o gestor edita (data, hora, tipo) e exclui a batida na ficha; o esquecimento aprovado cria uma batida nova. | No desenho e na regra nova do dono (ESPEC): a batida nunca é apagada nem trocada; a correção é um registro de ajuste com o valor antigo, o novo, quem fez, quando e por quê; a folha mostra "aj". | Origem: decisão do dono de 11/09/2026, "manter exclusão e alteração de batida pelo administrador" (spec ponto-batida-nao-se-perde-no-navegador). | Opções: (a) só ajuste; (b) editar e excluir como hoje. Recomendo (a): é o que sustenta a frase "Registros originais nunca são sobrescritos". | Decisão: (a). Correção só por ajuste; editar e excluir viram "Ajustar" e "Desconsiderar".
- **P66.** Fica (o desenho não mostra): a tela geral de auditoria do sistema, com "desfazer". Fica? (Se o P65 for "a", o desfazer não pode mais trocar nem apagar batida.) | Decisão: fica. Com o P65 (a), o desfazer não troca nem apaga batida.

### Configurações

- **P67.** Aba Configurações. Hoje: não existe; a configuração do ponto está espalhada (Jornada do escritório, Sedes, Feriados). | No desenho: aba com as 8 funções, cada uma com a chave "Escritório" (e "Global" para o Master), o texto de ajuda e o aviso "Desativado pelo Master para todos os escritórios.". | Decisão: aceito.
- **P68.** Sedes dentro da aba. Hoje: página própria "Sedes do ponto". | No desenho: tabela "Sedes e raio de registro" com nome, latitude, longitude, raio em metros, "Usar minha posição", remover e "Adicionar sede". | Decisão: aceito.
- **P69.** Fica (o desenho não mostra): mapa das sedes e busca por CEP ou endereço. Fica? | Decisão: fica.
- **P70. (PERGUNTA)** Raio da sede nova. Hoje: 100 m. | No desenho: 150 m. | Origem: decisão do dono de 11/09/2026 ("o raio de 100 m já foi escolhido contando com ~60 m de imprecisão", spec ponto-batida-nao-se-perde-no-navegador). | Opções: (a) 150 m; (b) 100 m. Recomendo (b). | Decisão: (a) (contra a recomendação acima). 150 m só para sede nova; as sedes de hoje não mudam.
- **P71.** Feriados dentro da aba. Hoje: página "Feriados e Folgas" com Data, Nome e Recorrência. | No desenho: "Feriados do escritório" com Data, Nome e Abrangência ("Todas as sedes" ou uma sede) e o texto "Os nacionais já estão incluídos. Cadastre aqui os estaduais e municipais, por sede.". A abrangência por sede é nova. | Decisão: aceito.
- **P72.** Fica (o desenho não mostra): feriado que se repete todo ano e as folgas. Ficam? | Decisão: fica.
- **P73.** Limite e prazo do banco. Hoje: não existem. | No desenho: campos "Limite do banco (horas)" (120) e "Prazo de compensação (meses)" (6, de 1 a 24). Depende do P51. | Decisão: aceito.
- **P74.** Permitir trabalho externo. Hoje: não existe. | No desenho: caixa "Permitir trabalho externo, com motivo e análise" (ligada). Depende do P18. | Decisão: aceito.
- **P75.** Fica (o desenho não mostra): "Jornada do escritório" (carga, alertas, validação do repouso e do descanso entre jornadas, blocos de horário). Fica? | Decisão: fica.

### Facial

- **P76.** Bater ponto com o rosto. Hoje: não existe. | No desenho: botão "Registrar com reconhecimento facial" (ou facial obrigatória), câmera em tela cheia com "Posicione o rosto na área de marcação", "Pisque os olhos", "Mantenha a posição". | Decisão: aceito. Contratar o fornecedor com prova de vida é do dono (seção 10).
- **P77.** Autorização antes do primeiro uso. Hoje: não existe. | No desenho: janela "Cadastro facial" com os textos da LGPD, a caixa "Li e autorizo o uso do reconhecimento facial para o registro do meu ponto." e o botão "Não autorizo" (quem recusa bate pelos outros meios, sem penalidade). | Decisão: aceito.
- **P78.** Ajustes da facial. Hoje: não existe. | No desenho: "Opcional: a pessoa escolhe" ou "Obrigatória em toda batida", "Guardar foto de cada batida facial", guardar por 6, 12 ou 24 meses e "Excluir meu cadastro". | Decisão: aceito. Depende do mesmo fornecedor (D10, seção 10).

### Avisos e sons

- **P79.** Tela cheia de validação. Hoje: não existe. | No desenho: anel que se completa com "Validando Entrada" e as etapas ("Horário oficial confirmado", "Localização validada", "Registro selado"); depois tela verde "Ponto registrado" (fecha sozinha em 10 s) ou vermelha "Ponto não registrado" com "Tentar novamente". | Decisão: aceito.
- **P80.** Aviso flutuante. Hoje: não existe neste formato. | No desenho: faixa colorida no rodapé que some em 3 segundos ("Entrada registrada às HH:MM:SS.", "Justificativa abonada." e outros). | Decisão: aceito.
- **P81.** Sons e botão de som. Hoje: som só na janela de alerta de horário, e "Ouvir os toques do ponto" no Meu Perfil. | No desenho: "plim" quando registra, som diferente quando falha e um botão de alto-falante no cartão para silenciar. | Decisão: aceito.
- **P82.** Vibração. Hoje: não existe. | No desenho: o celular vibra curto no sucesso e três vezes no erro. | Decisão: aceito.
- **P83.** Lembrete de saída. Hoje: a janela de alerta de horário cuida disso (P84). | No desenho: uma vez por dia, passada a saída prevista, aviso âmbar "Passou do horário previsto. Não esqueça de registrar a saída." e notificação do navegador "BlueJus Ponto". | Decisão: aceito.
- **P84.** Fica (o desenho não mostra): janela de alerta de horário a cada 60 s (entrada não registrada, intervalo, volta, saída, aviso das 6h), título piscando e som por tipo. Fica? | Decisão: fica.
- **P85.** Fica (o desenho não mostra): cartão diário "Registro de ponto pendente" com "Lembrar em 10 min" e "Dispensar até amanhã". Fica? | Decisão: fica.
- **P86.** Fica (o desenho não mostra): avisos no sininho (justificativa enviada ao gestor; abonada ou rejeitada ao colaborador). Fica? | Decisão: fica.

### Exportações

- **P87.** Baixar o PDF direto. Hoje: botão "Visualizar / Exportar", que mostra o PDF numa janela antes. | No desenho: "Baixar PDF" baixa direto o arquivo "folha_ponto_NOME-MM-AAAA.pdf" e avisa "Folha baixada em PDF. Código BJF-...". | Decisão: aceito.
- **P88.** Fica (o desenho não mostra): exportar a folha em XLSX. Fica? | Decisão: fica.
- **P89.** Fica (o desenho não mostra): ver o PDF antes de baixar e o gestor exportar a folha de outro colaborador. Fica? | Decisão: fica.

### Cabeçalho e telas globais

- **P90. (decidido)** Cabeçalho, menu do topo e camadas globais: fica a moldura do sistema. Hoje: o cabeçalho azul do sistema já segue os desenhos (nome, papel, tela cheia, "Indicar o BlueJus"). | No desenho: o mesmo cabeçalho, o menu branco de telas sem o Ponto, e as camadas de dicas, idioma, tema comemorativo, aviso do plano, lembretes da Agenda, Central de Comunicação. | Fica como o sistema tem; a tela faz só o conteúdo e o link do Ponto entra no menu que já existe (o menu lateral já tem "Ponto Eletrônico").
- **P91.** Fica (o desenho não mostra): atalho do ponto no avatar ("Bater ponto", "Em jornada desde", "Em intervalo desde", "Regularizar ponto de ontem"). Fica? | Decisão: fica.
- **P92.** Fica (o desenho não mostra): no Meu Perfil, a linha "bate ponto / sem ponto" e "Ouvir os toques do ponto". Fica? | Decisão: fica.
- **P93. (decidido)** Tela desligada: fica a moldura do sistema. Hoje: o sistema já tem, para as outras telas, a página "em preparação" e a faixa vermelha "Esta tela está desativada para os usuários. Só o Master está vendo.". | No desenho: as mesmas, para o Ponto. | O Ponto passa a usá-las quando a chave da tela existir (P94).

### Liga e desliga do Master

- **P94.** Chave da tela Ponto. Hoje: o Master não tem chave do Ponto em "Telas e recursos". | No desenho: a tela "Ponto Eletrônico" ganha chave. | Decisão: aceito.
- **P95.** Chaves das funções. Hoje: não existem. | No desenho: 8 chaves (tela cheia, sons, comprovante, localização, facial, lembrete de saída, alertas de intervalo, aceite da folha), cada uma com "Global" (Master, vale para todos) e "Escritório" (o escritório decide, dentro do que o Master liberou). Lista na seção 7. | Decisão: aceito.
- **P96.** Valor inicial das chaves. No desenho: todas ligadas. Recomendo: tela, localização, sons, lembrete e alertas ligados; tela cheia ligada quando pronta; comprovante, aceite e facial desligados até as suas etapas e o jurídico. | Decisão: aceito o desenho, todas ligadas (contra a recomendação acima). Cada chave nasce ligada quando a sua etapa ficar pronta.

## 3. (b) O que é novo e o que depende de fornecedor ou de lei

| Item | Depende de | Risco | Recomendação |
|---|---|---|---|
| Biometria facial com prova de vida (P76) | Fornecedor (o protótipo só liga a câmera e simula) e lei (LGPD, dado sensível) | ALTO | Pesquisa de fornecedor fora do repositório; a comparação e a prova de vida são do fornecedor, nunca do navegador. Contrato com cláusula de operador de dados e, se o fornecedor for de fora, conferir a transferência internacional. Batida facial sem internet não existe. Fica por último, atrás de `ponto-facial`, desligada. |
| Template e retenção (P78) | Lei (LGPD) e decisão do dono | ALTO | ESPEC: "Guardar template, não foto, salvo opção do escritório, com prazo de retenção e exclusão automática". Template fica no fornecedor ou cifrado; foto só com a opção ligada, no armazenamento de arquivos privado (skill `armazenamento-de-arquivos`), com purga diária pelo agendador. "Excluir meu cadastro" apaga no fornecedor e aqui, com confirmação. |
| Autorização LGPD (P77) | Lei (LGPD, art. 11) e jurídico | ALTO | Guardar quem autorizou ou recusou, quando, IP e navegador, e a versão do texto. Na relação de emprego o consentimento é frágil: a recusa sem penalidade do desenho é obrigatória, e o jurídico decide a base legal (anotar em `bluejus-coordenacao/pacote-juridico.md`). Relatório de impacto antes de abrir aos clientes. |
| Raio da sede validado no servidor com a precisão do GPS (P18, P70) | Decisão do dono (P18) | ALTO | O servidor já valida por distância (hoje sem a precisão). Proposta: aceita se distância menos precisão for até o raio, com teto de precisão (acima dele, pede nova leitura); a precisão já é gravada. Limite conhecido (F17, spec seguranca-b-ponto): a posição vem do navegador e pode ser falsa; o servidor não a confirma. |
| Batida fora do raio como trabalho externo (P18, P74) | Decisão do dono | ALTO | Batida marcada "trabalho externo" com o motivo, mais uma justificativa `trabalho_externo` em análise ligada à batida. Enquanto em análise, a batida conta ou não conta? Recomendo contar e mostrar "Em análise" (o desenho não diz). |
| Comprovante com NSR, código BJP e HMAC (P48, P49) | Lei (Portaria MTP 671/2021, a conferir) e segredo novo | ALTO | NSR sequencial por escritório, gerado no servidor, sem buraco nem reuso, independente da chave do comprovante. Código de 12 caracteres sem I, O, 0 e 1 (cerca de 60 bits) tirado do HMAC dos dados da batida com uma chave secreta só do servidor (variável nova: o Samuel cria em produção). A imagem pode ser desenhada no navegador a partir dos dados assinados; a prova é o código consultado no servidor. Biblioteca do QR servida pelo próprio sistema, não pelo jsDelivr. Conferir com o jurídico os campos que a portaria pede no comprovante. |
| Rota pública `/ponto/validar/{codigo}` (P50) | Lei (LGPD) e decisão do dono | ALTO | Sem login, então mostra o mínimo: "Comprovante autêntico." ou "Código não localizado.", tipo (Entrada, Folha), data e hora, nome do escritório (empregador) e NSR. Do trabalhador, só iniciais ou primeiro nome (o desenho mostra o nome inteiro: decidir). Nunca CPF, localização, foto, método ou escritório de outra pessoa. Limite de tentativas por IP, sem indexação por buscador, mesma resposta para código errado e inexistente. O QR do desenho aponta para `www.bluejus.com.br` (o site): trocar pelo endereço do sistema. Entra no catálogo do Master como tela pública (como `pushpub`), sem depender da chave da tela `ponto`. |
| Folha com código BJF e aceite mensal (P33, P34) | Lei (a conferir) e decisão do dono | ALTO | Cada emissão do PDF grava código, HMAC, competência, pessoa, quem emitiu e o resumo do conteúdo, para o código ser validado depois. Aceite só de mês fechado, com IP e navegador, sem desfazer; se a folha mudar depois do aceite (ajuste posterior), a tela mostra "alterada depois do aceite" e pede nova assinatura (decidir). |
| Frases fixas do PDF (P36) | Jurídico | MÉDIO | Não afirmar "horas extras autorizadas previamente" nem "feriados serão compensados" sem dado que prove; se o dono quiser, vira texto configurável por escritório. |
| Trilha de auditoria encadeada (P64) | Nada (técnica) | ALTO | Tabela própria por escritório; cada evento guarda o selo do anterior e o próprio, feito com HMAC no servidor (com hash simples, quem tem acesso ao banco recalcula a cadeia). Inserção com trava por escritório para dois eventos ao mesmo tempo não quebrarem a cadeia. A aba mostra o resultado da última conferência (a conferência inteira roda no agendador). A purga do escritório (LGPD) apaga a cadeia inteira dele. O `audit_log` geral continua. |
| Batida só inserção com ajuste (P65, P29) | Decisão do dono | ALTO | Ajuste guarda batida (ou vazio, quando é inclusão), tipo, valor anterior, valor novo, autor, data, motivo e justificativa ligada. "Editar" e "Excluir" da ficha viram "Ajustar" e "Desconsiderar"; o esquecimento aprovado vira ajuste de inclusão; reverter o abono desfaz o ajuste com outro ajuste. A escolha única de batidas por dia passa a ler batida mais ajustes. O passado editado antes disso fica como está (o antes e depois já está no `audit_log`). |
| Fila sem internet (P20) | Decisão do dono | ALTO | Fila no aparelho cifrada (chave do navegador que não sai dele: protege de leitura casual, não do dono do aparelho). Cada batida leva um identificador único para não entrar duas vezes. O servidor grava a hora do aparelho, a hora da chegada e a marca "enviada sem internet"; teto de idade da batida guardada (exemplo: 72 h) a decidir. Sem facial e sem comprovante até sincronizar. |
| Hora do servidor (NTP) (P5) | Nada no código; relógio da VPS | MÉDIO | A batida já usa a hora do servidor na chegada. A tela mostra a hora do servidor (enviada ao abrir e ajustada pelo atraso). Conferir o NTP da VPS é tarefa do Samuel (montar o comando; sem ssh pelo Claude). A hora do aparelho passa a ser guardada só como informação. |
| Alertas que não bloqueiam (P21) | Decisão do dono | ALTO | O retorno e a entrada passam com aviso; a folha marca "não conforme" no quadro de intervalos; o PDF mostra "Conforme" ou "Não conforme" como no desenho. Os mínimos vêm da Jornada do escritório (não o 1h e 11h fixos). |
| Banco de horas por lotes (P51, P73) | Decisão do dono e acordo de cada escritório | ALTO | Lotes calculados a partir do saldo de cada mês (crédito do mês, débito abatendo o crédito mais antigo, vencimento em "mês mais prazo"). Limite 120h e prazo 6 meses só como padrão editável. Decidir: zerar em janeiro sai? horas pagas entram como débito ou crédito do mês? o saldo de antes do início do lote ("Sem data de origem") como fica? |
| Portarias citadas | Lei (jurídico) | ALTO | A conferir antes de abrir aos clientes (ESPEC já pede): vigência da Portaria MTP 671/2021 diante da Portaria Consolidada MTE nº 1/2025 (texto do DOU não conferido); requisitos para REP-P (programa) e se o BlueJus precisa cumpri-los (registro do programa, arquivos de fiscalização, assinatura eletrônica); vigência do art. 13 da Portaria 3.626/1991, citado na folha (pode ter caído com a consolidação de 2021). Até a resposta, não escrever "Base Legal" no PDF ou escrever só o que o jurídico confirmar. Pela regra da casa, o jurídico não trava: anotar em `pacote-juridico.md`. |
| Feriado por sede (P71) | Nada | BAIXO | Coluna nova no feriado (sede opcional); a lotação da pessoa decide qual sede vale. |
| Visão da equipe (P54, P55, P57) | Nada | MÉDIO (permissão) | Consulta nova, por escritório, com as permissões de hoje (P58). Abono em lote chama a mesma regra do abono individual, uma por uma. |
| Tela cheia, sons, vibração, notificação (P79 a P83) | Nada | BAIXO | Só navegador. Respeitar "movimento reduzido" (o desenho já faz). O som não pode perder a liberação do áudio do alerta (decisão de "atualiza sem recarregar"). |
| Liga e desliga em duas camadas (P94 a P96) | Nada | MÉDIO | Global no catálogo do Master (tabela `recurso_estado` já existe); escritório numa configuração nova do Ponto. Função ativa = Global e Escritório. |

**Decisões de 09/11/2026 que mudam esta tabela** (onde a tabela diz outra coisa, vale isto):

1. Validação pública (P50): mostra o nome inteiro do trabalhador, como o desenho; continua sem CPF e sem localização.
2. Comprovante e folha (D9, P34, D17): hora, NSR, código e dados saem do servidor; o PDF da folha é imagem gerada no
   servidor, com o arranjo do desenho (não mais PDF em texto).
3. Folha alterada depois do aceite (D19): a tela mostra "alterada depois do aceite" e pede nova assinatura.
4. Frases fixas e "Base Legal" do PDF (P36, P34): ficam como o desenho; as duas primeiras frases e as portarias vão
   anotadas no pacote jurídico, sem travar.
5. Trilha encadeada (D5): leva o que o desenho grava; mudanças de sede, feriado, limite, prazo, modo facial e foto ficam
   só no `audit_log`.
6. Banco por lotes (P51 a): não zera mais em janeiro; as horas pagas seguem no saldo. O saldo de antes do início dos
   lotes ("Sem data de origem") se fechou na E17 (ver lá).
7. Raio (P70 a): sede nova nasce com 150 m; as sedes de hoje não mudam.
8. Sem decisão no arquivo de 09/11, valem as recomendações desta tabela: a batida de trabalho externo conta enquanto em
   análise (P18) e o teto de idade da fila sem internet (P20) se fecha na E14.

**O que o desenho afirma de lei (literal, inventário §7):**

1. Aba Banco de Horas: "Horas negativas abatem primeiro os créditos mais antigos. O prazo padrão é de 6 meses, próprio do acordo individual (art. 59, § 5º, da CLT), e pode ser ajustado em Configurações conforme o acordo do escritório."
2. Janela Cadastro facial: "Para registrar com o rosto, é preciso um cadastro facial único. A imagem do rosto é dado biométrico, classificado como dado pessoal sensível pela LGPD (art. 5º, II)."
3. Janela Cadastro facial: "O BlueJus usa esse dado somente para confirmar a sua identidade no registro de ponto. Ele não é compartilhado com terceiros para outra finalidade, e o cadastro pode ser excluído a seu pedido."
4. PDF, caixa "Base Legal:": "Art. 74, §2º da CLT  |  Portaria MTP nº 671/2021  |  Art. 13 da Portaria nº 3.626/91"
5. PDF: "(Esta folha de ponto substitui o Quadro de Horário de Trabalho, inclusive o de menores.)"
6. PDF, Regime de Jornada: "Banco de Horas: Sim (acordo individual)"; "Intervalo Intrajornada: 1h00 (mínimo)"; "Intervalo Interjornada: mínimo 11h00".
7. PDF, declaração: "Declaro que os registros acima representam fielmente minha jornada de trabalho."
8. Janela "Antes de registrar": "O alerta não impede o registro: a folha deve refletir o horário real."
9. Regras sem citação no texto: intervalo mínimo de 1h, descanso de 11h entre jornadas, descanso semanal depois de 6 dias.

## 4. Pontos para decidir que o próprio desenho tem

Decisão de 09/11/2026: "aceito" segue a recomendação desta spec; "não" quer dizer que vale o que o desenho faz.

| # | O que o desenho faz | Recomendação | Decisão |
|---|---|---|---|
| D1 | "Jornada semanal 44h00m" fixa no cartão (L137); não segue a escala. | Mostrar a carga da jornada que vale hoje para a pessoa (leitura da jornada com vigência, sem mexer nela). | Aceito. "44h00m" fixo contradiz a jornada com vigência em vigor. |
| D2 | NSR = quantidade de comprovantes + 1; com o comprovante desligado o NSR não sobe (L522). | NSR sempre, no servidor, sequencial por escritório, mesmo com `ponto-comprovante` desligado. | Aceito. Contado no navegador repete número; no servidor, sem buraco. |
| D3 | Falta abonada continua descontando a carga do dia (L620); o abono só vira ajuste no esquecimento. | Seguir o sistema: abonada zera o negativo do dia; abono técnico não perdoa jornada (spec ponto-abono-nao-perdoa-jornada). | Aceito. É regra em vigor; o desenho não mostra outra. |
| D4 | Exclusões sem confirmação: justificativa em análise, sede, feriado, cadastro facial. | Pedir confirmação, na moldura de janela do desenho (convenção do sistema; sedes e feriados já confirmam hoje). | Aceito. Sede e feriado já confirmam; atestado e cadastro facial se perdem de vez. |
| D5 | Mudanças de sede, feriado, limite, prazo, modo facial e foto não vão para a trilha. | Entram na trilha do Ponto (hoje já vão ao `audit_log`). | Não (contra a recomendação). Vale o desenho: o `audit_log` já registra essas mudanças. |
| D6 | Dados do empregado e do empregador fixos para o Farlei (razão social diferente do nome exibido, CPF e CTPS mascarados, código "0001", lotação, admissão, responsável "FARLEI ASSIS DA ROCHA OAB/DF 49.613", "Sim (acordo individual)"). | Tirar das mesmas fontes do PDF de hoje; nada fixo. O que o sistema não tem (razão social separada do nome, quem assina pelo empregador com OAB, tipo de acordo do banco) vira campo novo só se o Samuel aceitar. | Aceito. Nada fixo; o escritório e o vínculo já têm os dados; nenhum campo novo. |
| D7 | Competência padrão fixa em 11/2026 e lista desde 10/2026 (L430, L773). | Mês corrente pelo relógio do servidor; lista desde o início da contagem da pessoa (respeitando o P23). | Aceito. Mês fixo quebra a tela no mês seguinte. |
| D8 | Pendências de uma lista fixa (L407). | Cálculo de hoje (`ListarPendenciasDeRegistroUseCase`, 30 dias, todos os escritórios). | Aceito. Lista fixa mostraria pendência falsa. |
| D9 | Hora oficial, código e comprovante calculados no navegador. | Servidor (ESPEC). | Aceito. Feitos no navegador são falsificáveis. |
| D10 | Foto da facial capturada e não gravada. | Template no fornecedor; foto só com a opção do escritório e retenção (seção 3). | Aceito (LGPD). O fornecedor é pendência do dono (seção 10). |
| D11 | Sede sem coordenada não bloqueia; GPS negado deixa bater (modo de demonstração). | Seguir o servidor de hoje: fora do home office, sem localização não registra; sede sem coordenada é ignorada. | Aceito. O próprio desenho mostra a tela vermelha sem localização (P10). |
| D12 | Links do cabeçalho apontam para versões antigas de outras telas. | Menu do sistema (P90). | Aceito. Decidido antes (P90). |
| D13 | `raio`, `raioOff` e `trocaRaio` no código sem campo na tela. | Raio por sede, como hoje. | Aceito. A tabela de sedes do desenho já tem o raio por sede. |
| D14 | Dicas marcadas com `data-tip`, que nenhuma biblioteca da pasta desenha. | Padrão de dica do sistema. | Aceito. Os textos são do desenho; a camada de dicas é da moldura. |
| D15 | Tabelas fora da Folha sem listra e sem azul ao passar o mouse. | Seguir o desenho (só a Folha tem listra e azul). | Aceito. |
| D16 | Barra "Ver como" e "Próxima batida", nota "Dados de exemplo", equipe fictícia, folha de outubro importada. | Não entram: são do protótipo. | Aceito. "Ver como" trocaria de papel (segurança); o resto é dado de exemplo. |
| D17 | PDF da folha desenhado em imagem no navegador. | PDF gerado no servidor, em texto (como hoje), com o arranjo do desenho, código BJF e QR. Imagem só se o Samuel preferir. | Não (contra a recomendação). Vale o desenho: PDF em imagem, gerado no servidor (D9). |
| D18 | Abonar esquecimento cria ajuste; hoje cria batida nova. | Segue a resposta do P65. | Aceito. Segue o P65 (a): vira ajuste. |
| D19 | Aceite sem desfazer e sem regra para folha que muda depois. | Ver seção 3 (folha BJF): mostrar "alterada depois do aceite" e pedir nova assinatura. | Aceito. Selo de aceite em folha alterada atestaria o que a pessoa não viu. |
| D20 | QR para `https://www.bluejus.com.br/ponto/validar/CÓDIGO` (o site). | Endereço do sistema. | Aceito. O site não tem a rota; a validação pelo QR quebraria. |
| D21 | Feriados nacionais fixos no código do desenho. | Seguir o sistema (comando de carga dos nacionais por escritório). | Aceito. O comando `SeedFeriadosNacionaisCommand` já existe. |
| D22 | Selo da Folha "Abonada" e da aba "Abonado" (gêneros diferentes). | Seguir o desenho em cada lugar. | Aceito. |

## 5. (c) Etapas em PRs pequenos, em ordem

**Regras para todas as etapas.** Entra o que foi decidido na seção 2 (aceito, fica ou a opção escolhida); P58 não entra.
Cada chave de função entra no catálogo do Master na etapa da sua função e nasce ligada quando ela ficar pronta (P96);
a facial é a exceção, desligada até o contrato (E18). As pendências do dono (seção 10) não travam nenhuma etapa: cada
uma tem um gancho no código. Componentes do desenho, com medidas e cores tiradas do
CSS do desenho (inventário §0 e §2), nunca modal do Bootstrap, Select2, cartão, KPI ou moldura do AdminLTE; CSS próprio
da tela, com prefixo, sem tocar o CSS global. Skill `desenho-fiel` antes de cada etapa de tela; teste de arranjo por filho
direto; conferência item a item ✓/✗ no fim; textos sem travessão (seção 8). Cada PR leva o arquivo em `mudancas/` em
linguagem simples. Uma migration por vez, número pedido ao Coordenador. Arquivos quentes (`base.html.twig`,
`_sidebar.html.twig`, `app/config/*.yaml`, `composer.json`, migrations): avisar antes. Risco de todas: ALTO pela regra da
casa (ponto); no bloco 1 a mudança é só de aparência, o rigor da revisão é o de tela.

**Arquivos da frente jornada-vigencia** (nenhum PR aberto dela em 09/11; pendentes dela: trava nas versões, cabeçalho
"Jornada alterada em dd/mm" no PDF e XLSX, padrão do escritório com vigência): `JornadaColaboradorController.php`,
`_jornada_colaborador_tab.html.twig`, `HistoricoDeJornadas.php`, `JornadaColaboradorRepository.php`, os 3 UseCases de
versão, `FolhaPontoBuilder.php`, `PontoController::montarDadosFolha`, `folha_pdf.html.twig`, `FolhaPontoXlsxExporter.php`,
`JornadaTenant` e `_modal_jornada_tenant.html.twig`. Também foram tocados por ela: `PontoController.php` e
`TenantController.php`.

### Bloco 1: só tela (componentes do desenho sobre o motor de hoje, sem migration)

**E1. Cartão Registrar Ponto.**
- Entra: P1 a P6, P8 (aviso de batida repetida no botão do tipo), P9 (fora da área, Home Office e erro no molde do
  desenho), P12 (mínimo do intervalo da Jornada do escritório), P13 e P17 (ficam), P14, P15, P16 com D1 (carga da
  jornada da pessoa), P19 (fica), P7 (a: o tipo já batido fica verde e sem clique; com os 4 feitos, "Jornada de hoje
  concluída", cinza e sem clique), D11. O P5 entra aqui só como a linha "Hora oficial" com a hora do servidor; o resto
  do P5 é da E13.
- P10 (b) e P11 (b) entram em parte: até a E8, a caixa de hoje (`#batida-aviso`, no molde do desenho) responde a todos
  os resultados da batida, e a caixa da localização traz o passo a passo de hoje. A tela vermelha do GPS (P10) e a parte
  do desenho do P11 (tela cheia, P79, e aviso flutuante, P80) vão para a E8.
- Decisões da etapa (09/11/2026, Coordenador):
  - a virada do dia segue o desenho (os 4 tipos livres e sem horário, "Bater Ponto: Entrada"; o aviso da virada fica) e
    é detectada pela hora do servidor: o instante da carga mais o tempo passado, contra a próxima meia-noite no fuso do
    servidor; o dia e o fuso do aparelho não entram;
  - o mínimo do aviso do intervalo (P12) segue a chave `validacaoRepousoHabilitada` da Jornada do escritório:
    desligada, o mínimo não aparece (o tempo do intervalo ao vivo, sim: ver "Funções do sistema que voltam", abaixo);
  - as setas do teclado andam entre os tipos livres como num grupo de rádio (tabindex móvel); o desenho só tem o Esc;
  - o botão de som e o de mudo (P81) vão para a E8;
  - os parâmetros de leitura do GPS são os do sistema (spec `ponto-gps-erro-que-explica`), não os do protótipo;
  - a saída prevista usa a escala: antes do repouso batido, entrada mais a duração da escala de hoje (com o almoço);
    depois dele, entrada mais a meta do dia e o intervalo (o batido; no repouso, a conta de "Funções do sistema que
    voltam", abaixo);
  - os avisos que mandavam olhar "a lista abaixo" apontam para os horários nos botões dos tipos, acima.
- Funções do sistema que voltam (regra 2: função do sistema não sai; pedido do Samuel de 09/11/2026, aprovado pelo
  Coordenador; branch `ponto-desenho-funcoes`, sem migration):
  - tempo do intervalo ao vivo: antes da E1 o "Repouso: HH:MM:SS" contava o intervalo a cada segundo, com ou sem a
    validação do repouso; a E1 deixou só o mínimo e só com a validação. Volta dentro do aviso do P12 (o texto do desenho
    fica, com o tempo acrescentado): "Intervalo em andamento há 00:42:10. O mínimo de 1h se completa em HH:MM.",
    "Intervalo mínimo de 1h cumprido. Em intervalo há 01:05:00." e, sem a validação, "Intervalo em andamento há
    00:42:10.". O "1h" é o mínimo da Jornada do escritório. O tique é o do relógio do dia (um `setInterval` de 1 s, que
    a troca depois da batida não repete); o início é a hora da batida de repouso que o cartão já tem, sem busca nova.
    O "se completa em HH:MM" (o que falta para o mínimo) arredonda para cima, no minuto: 17min50s dá 00:18, e os
    últimos 59 s dão 00:01, nunca 00:00 (revisão de 09/11/2026);
  - saída prevista durante o intervalo: antes da E1 a "Previsão de saída" ficava na tela o dia todo, também no
    repouso; a E1 a escondia enquanto o aviso do intervalo aparecia. Volta como " Saída prevista às HH:MM." no fim do
    aviso do intervalo, só quando `saidaPrevista()` tem valor (sem meta ou sem escala, não aparece). No repouso, a conta
    é coerente com a de antes dele (decisões do Coordenador de 09/11/2026, na revisão): entrada mais a meta mais o maior
    entre o intervalo já feito e o piso, recalculada a cada tique de 1 s junto com o tempo. O piso é o maior entre o
    almoço da escala (duração menos meta) e o mínimo que o servidor aplica (o da Jornada do escritório com a validação do
    repouso ligada; zero desligada), com ou sem almoço na escala (re-revisão de 09/11/2026: escala de 8h sem almoço e
    mínimo de 1h ligado mostra 17:00, não a saída antes do retorno mais cedo possível). Sem escala (duração nula), a saída prevista não aparece no repouso, como antes dele. Escala 08-12/14-18 (meta
    480, mínimo 60): às 11:00 mostra 18:00, às 12:00 no repouso continua 18:00 e com 2h10 de repouso mostra 18:10. Com
    o retorno batido, vale o intervalo batido, como já era. Antes da E1 não era assim: a "Previsão de saída" era fixa, entrada
    mais a duração da escala, calculada só na carga e sem contar o intervalo real.
- Depende de: nada técnico.
- Migration: não.
- Arquivos: `templates/ponto/index.html.twig`, CSS novo da tela (`public/css/ponto-eletronico.css`, prefixo `.pe-`);
  `PontoController::index` (hora do servidor e próxima virada); `e2e/tests/ponto-batida-cerca.spec.js` (seletores
  novos). `public/js/ponto-batida.js` ficou como estava. Frente jornada-vigencia: `PontoController.php` (o `index` foi mexido no §10.4);
  só leitura de `HistoricoDeJornadas` para D1. Avisar antes.
- Testes: funcional da `/ponto` com o arranjo do desenho (filho direto); `BotaoDoPontoRespondeAoToqueTest` ajustado ao
  P7 (a): responde ao toque enquanto houver tipo a bater e fica cinza com os 4 feitos; os do erro de GPS continuam verdes
  com o P10 (b); a troca das 4 regiões depois da batida ("atualiza sem recarregar") continua tudo ou nada com os ids
  novos; `node --check` no JS.

**E2. Folha no molde do desenho.**
- Entra: P22 (sem "Assinar folha"), P23, P24, P25 (filtro do desenho: só dias com batida e feriado com horas; o dia sem
  batida some da tela e continua descontando do saldo), P26, P27, P28 (fica), P30 (a: "+" em qualquer dia passado e
  hoje; feriado sem "+"; "Em análise" com ampulheta na coluna Justificativa), P31 ("Não abonada" e o horário do abono
  parcial ficam), P32 (fica), D7, D15, D22.
- Decisões da etapa (09/11/2026, Coordenador e implementação):
  - a ficha do colaborador ganha o mesmo molde e mostra todos os dias do mês; o filtro do P25 é só da `/ponto`
    (parâmetro `somenteDiasComBatida`). A ficha carrega o CSS da folha pelo próprio partial;
  - ficam do sistema, no estilo do desenho: o selo da jornada na barra da ficha ("40h00m/sem (Personalizada)"), o sinal
    de registro em outro escritório, a dica do "Registro incompleto", "A conferir", "Home Office", as batidas fora da
    conta do gestor, "Ver PDF" (P89, ainda no modal do sistema até a E16/E4) e "Baixar XLSX" (P88, agora baixa direto);
  - o "+" (P30 a) vale em qualquer dia passado com batida, inclusive fim de semana, e no dia de hoje; feriado e dia
    antes do início da contagem ficam sem ele. A batida que já tem esquecimento pendente ou abonado também fica sem "+"
    (condição 8 do sistema, para não criar duplicata, hoje inclusive); o "Em análise" aparece na coluna Justificativa.
    O "+" de hoje é função do sistema que volta (regra 2; correção de 09/11/2026, pedido do Samuel aprovado pelo
    Coordenador): a E2 o tinha tirado, mas antes dela ele já valia hoje (`chaveDia <= dataHoje`), e a spec
    ponto-atalho-esquecimento-registro diz que "esqueci de bater a entrada hoje de manhã" é o caso mais comum do atalho.
    O "+" aparece também na batida de hoje que ainda vai acontecer (o retorno e a saída de quem acabou de entrar), como
    era antes da E2; o horário futuro é recusado no envio (`HorarioFuturoDaBatida`). O caso "aprovado depois de a
    pessoa bater a mesma batida" já é recusado (`RegistrarBatidaDoEsquecimentoUseCase::recusa`). O risco que fica é a
    ordem inversa: às 10h a pessoa pede "repouso 09:30", o pedido é aprovado e a batida real nunca acontece (o tipo já
    está preenchido pelo ajuste, e nada confere que a pessoa saiu mesmo para o repouso). Quem confere é o gestor, na
    análise do pedido;
  - o selo "Fora de sequência" vale quando um horário é menor que o anterior e vence os outros; o feriado trabalhado
    ganha o selo "Feriado" na coluna Semana (o motor escrevia "FERIADO - "); o feriado sem batida tem o selo no Saldo dia;
  - o tipo da justificativa vai para a dica do selo (o desenho não tem a linha de baixo); "Não abonada" fica vermelha
    como "Recusada"; a coluna mostra a justificativa que o sistema já escolhe por dia, não a primeira da lista;
  - a lista do mês (P23, D7) vai do mês do início da contagem neste escritório até o mês corrente do servidor;
  - revisão e conferência da E2 (09/11/2026): ficam do sistema as dicas dos cabeçalhos "Trabalhado", "Saldo dia" e
    "Saldo mês" (vinham da folha antiga) e o nome do feriado na dica do selo "Feriado" (antes ia no texto, "FERIADO - nome");
    na ficha, o zero do dia sem batida fica cinza (com batida, verde como no desenho). Esquecimento pendente num dia em
    que outra justificativa vence a escolha (um atestado abonado, uma recusada mais nova) ganha o "Em análise" ao lado do
    selo escolhido: antes da E2 a ampulheta ficava na célula da batida, e sem esse segundo selo o pedido sumiria da folha;
  - ficam como o motor entrega, e a tela difere do desenho: no dia em andamento, o "Trabalhado" mostra as horas apuradas
    até a última batida e o "Saldo mês" fica vazio até a saída (o desenho mostra o acumulado até ontem, mas a /ponto só
    recebe do motor os dias com batida ou justificativa, e o acumulado da última linha recebida deixaria de fora o desconto
    dos dias escondidos; mostrar esse número pede um campo novo no motor: pendência "Saldo mês do dia em andamento" da
    E17, decidida pelo Coordenador em 09/11/2026 pela regra 1); o "Registro incompleto"
    segue a marca do motor (dia com entrada e saída sem repouso mostra saldo);
  - consequência do P25 a conhecer: o "Saldo mês" da última linha visível pode diferir do "Saldo do mês" do rodapé
    (que só aparece com horas pagas). O rodapé é o acumulado da última linha que o motor entrega (dia com batida ou com
    justificativa), como antes da E2; o P25 esconde o dia só com justificativa, e o desconto dos dias sem batida até ele
    entra no rodapé sem aparecer em linha nenhuma. O número certo é o do rodapé, que é o de antes. (Mudou na E17: o
    rodapé da /ponto e o da ficha passam a vir do banco da competência, sem as horas pagas, e contam também os dias sem
    batida depois da última batida do mês; ver E17, "Rodapé".);
  - com a lista do P23 e o "+" do P30 a, o atalho do esquecimento aparece em meses antigos; o servidor já aceitava
    qualquer dia passado pelo modal completo e o sistema não tem mês fechado, então não há exposição nova;
  - os números são os do motor: o rodapé e o "Saldo mês" de cada linha vêm das linhas sem o filtro; o PDF, o XLSX e
    `montarDadosFolha` não mudaram (na E17 o PDF, o XLSX e a ficha passam a usar a jornada da folha; ver E17, "Jornada").
- Depende de: E1 (CSS e grade).
- Migration: não.
- Arquivos: `templates/ponto/_folha_table.html.twig` com as células em `_folha_saldo_dia.html.twig`,
  `_folha_justificativa.html.twig` e os formatos em `_folha_macros.html.twig` (também usado na ficha do colaborador);
  `templates/ponto/index.html.twig` (parâmetros da folha e seletor da troca sem recarregar);
  `public/css/ponto-eletronico.css` (seção Folha e celular até 760 px); `PontoController::index` (lista de meses do P23).
  `montarDadosFolha` não foi tocado.
- Testes: `FolhaDoDesenhoTest` (arranjo por filho direto, o que saiu contando zero, dia sem batida fora da tela com o
  saldo de antes, selos, meses, ficha com todos os dias, celular por classe, dia sem repouso ou sem entrada, "Em análise" ao lado da justificativa escolhida e "Saldo mês" vazio no dia em andamento) e `FolhaDoDesenhoCssTest` (valores do
  desenho); ajustados: `JustificativaEsquecimentoControllerTest` (dia só com abono sai, sábado passado ganha "+", hoje
  ganha "+" depois da correção de 09/11/2026, que também cobre o esquecimento pendente de hoje), `HorasPagasFolhaExibicaoTest` (cabeçalho "Saldo mês" e marcação do rodapé), `JornadaComVersoesPelaTelaTest`
  e os dois da troca sem recarregar (seletor novo). `FolhaExportacaoMesmaEscolhaTest` e os do PDF e XLSX sem mudança.

**E3. Cartão de abas: Pendências e Minhas Justificativas.**
- Entra: P37, P38 (fica), P39, P40 (Em análise, Abonado, Recusado em todas as telas, inclusive a ficha do gestor e o
  sininho), P41 (com confirmação antes de apagar, D4), P42 (fica), P43 (a: o primário e o Master veem "Abonar" e
  "Recusar" nas próprias justificativas), D8. A aba Banco de Horas entra na E17 (P51 a); até lá o número do topo (P2) é
  o saldo de hoje.
- Depende de: E1.
- Migration: não. Excluir a justificativa em análise é regra nova pequena (UseCase próprio, só o dono dela, só em análise,
  só no escritório dela; o anexo sai depois do commit). Abonar e recusar a própria (P43 a) chamam a mesma regra de
  análise de hoje.
- Decisões da etapa (implementada em 09/11/2026 e integrada na branch `ponto-desenho`, a de integração do Ponto, sobre a E2):
  - parciais NOVOS, só do index novo (decisão do Coordenador, 09/11/2026: o Ponto novo pode ficar atrás de uma chave,
    com o antigo de volta): `_abas.html.twig` (o cartão), `_abas_pendencias.html.twig`, `_abas_justificativas.html.twig`
    e `_janelas_justificativa.html.twig`. `_pendencias.html.twig` e `_justificativas_list.html.twig` ficam como estavam;
    o comportamento mora em `public/js/ponto-abas.js` (a tela não tem `fetch` sem prazo, BatidaNaoTravaNoGpsTest);
  - uma linha por justificativa, como no desenho (antes: uma por lote, com a situação só do primeiro dia). A situação, a
    exclusão e a análise são de cada dia, como na ficha do gestor. A troca do atestado continua valendo para o lote
    inteiro (o sinal do lápis olha os dias do lote no mês);
  - cada aba é um painel (`role="tabpanel"`) com as duas prontas do servidor; a troca é no navegador e grava a aba no
    endereço (`?aba=justificativas`) e no formulário do mês. O desenho troca o conteúdo; o painel a mais deixa a
    troca sem recarregar e a busca da tela depois da batida voltar na mesma aba. A aba das pendências (o número) e o
    painel delas são regiões trocadas depois da batida;
  - Pendências sempre aparece, com "Nenhuma pendência de registro." (antes o cartão sumia). O botão "Enviar registro de
    esquecimento" abre o "+" da folha com o mês aberto, senão o link `?esquecimento=` (o mesmo caminho do "Regularizar");
  - ficam do sistema, no molde do desenho: "Trocar de escritório" e a explicação da pendência de outro escritório (P38),
    o "Ver" do atestado, o lápis de editar (P42), "Não abonado" (vermelho da recusada) e o horário do abono parcial
    (subtítulo "Parcial: 08:00 às 12:00", P45). O "Motivo" do desenho (L184) ficou para a E4, que criou o campo (coluna
    nova `motivo`) e o mostra abaixo do tipo. Obs. da análise em análise: "Aguardando análise"; "Analisado em" vazio (seção 8);
  - P41 e D4: a lixeira abre a janela "Excluir justificativa" na moldura do desenho (L269 a L318); o "Excluir" da janela
    faz o POST (`ponto_justificativa_excluir`, JSON, token da página). `ExcluirJustificativaEmAnaliseUseCase`: busca
    pelo escritório da sessão (outro escritório: 404), só o dono (403), status lido com a linha travada (já analisada:
    422, sem fechar o EntityManager), o atestado sai depois do COMMIT e só sem outra referência no escritório;
  - P43 a: a regra mora em `AnaliseDaPropriaJustificativa::permite` (a própria: só `RegraDosPrimarios::podeGerirPrimarios`,
    o primário ativo ou o Master; a de outra pessoa segue a regra de hoje). Aplicada no servidor nas cinco rotas da
    ficha que analisam (abonar, recusar, aprovar todos, voltar para análise e lançar para si, que já sai abonada:
    decisão do Coordenador, 09/11/2026), pelo `TenantController::exigirQuePodeAnalisar`. Muda o comportamento da
    ficha: o gestor pelo perfil que não é primário nem Master deixa de analisar a própria lá (403) e, pela mesma
    decisão (`podeAnalisar`), a ficha dele mesmo não mostra Abonar, Recusar, Aprovar todos, Voltar para análise nem
    Justificar Falta; no lugar, uma linha diz o porquê. A aba mostra "Abonar"/"Recusar" pela mesma regra e manda o
    formulário para as MESMAS rotas da ficha (a regra de análise inteira, com a batida do esquecimento e o aviso, mora
    lá), com `volta=ponto` (volta para a aba, no mês);
  - P40 fora da tela nova num ponto só, `NomesDasSituacoesDaJustificativa` (`VIGENTES`): os selos e o "Voltar para
    análise" da ficha, o botão "Recusar", o "Motivo da recusa" e a confirmação da recusa na ficha (decisão do
    Coordenador, 09/11/2026), as mensagens da análise e da reversão no TenantController e o aviso do sininho
    ("recusada"). Os avisos antigos já gravados no sininho ficam como estão. "Aprovar Todos (n dias pendentes)" fica;
  - pontos de liga e desliga (sem chave nesta etapa): P40 em `NomesDasSituacoesDaJustificativa::VIGENTES`; P43 a na
    última linha de `AnaliseDaPropriaJustificativa::permite`; P41 no atributo da rota `ponto_justificativa_excluir`.
  - ajustes da revisão e da conferência (09/11/2026, Coordenador):
    - P40 completo: o aviso do atestado travado (lápis da aba e `SubstituirAnexoDoLoteUseCase`), a mensagem da edição
      de justificativa já analisada (`JustificativaJaAnalisadaException`) e o "(rejeitada)" do PDF e do XLSX da folha
      passam pelo `NomesDasSituacoesDaJustificativa` (chaves `anexo.travado`, `edicao.ja_analisada` e
      `folha.recusada`, "(recusada)", no feminino da Folha, D22). As chaves `lote.sem_pendente` ("Aprovar todos" da
      ficha) e `reverter.feito_com_batida` (voltar para análise um esquecimento abonado) entram no refactor da análise
      (E9), que reescreve esses dois trechos do TenantController. O "(pendente)" do PDF e do XLSX fica para a E16,
      que refaz o PDF (P34 a P36);
    - o invólucro `#ponto-regiao-folha` fica: o parcial da Folha (o mesmo da ficha) entrega, junto com a
      `section.pe-folha`, o formulário do mês, a janela de pré-visualização e o script dela. O invólucro os agrupa para
      o cartão de abas vir logo depois da Folha (`.pe-dir > #ponto-regiao-folha + section.pe-abas`) e para a troca sem
      recarregar pegar só o cartão. Sem efeito visual: o espaço de 16px fica entre o invólucro e o cartão;
    - excluir a justificativa tira a linha na hora, sem recarregar, e o número da aba acompanha (o desenho só tira a
      linha). Pendente: entra com a mudança do PontoController depois da E4, porque o flash "Justificativa de
      dd/mm/aaaa excluída." sai da rota de excluir.
- Arquivos: `templates/ponto/_abas*.html.twig`, `_janelas_justificativa.html.twig`, `index.html.twig` (coluna da
  direita e REGIOES_TROCADAS), `public/js/ponto-abas.js`, `public/css/ponto-eletronico.css` (seção do cartão de abas e
  da janela), `PontoController` (rota de excluir; `abaAberta` e `podeAnalisarAPropria` no index), `TenantController`
  (regra P43 a e `voltaDaAnalise`), `templates/tenant/_justificativas_tab.html.twig` e `NotificacaoService` (P40),
  catálogo `ponto_pendencias` (`vazia`).
- Testes: `ExcluirJustificativaEmAnaliseUseCaseTest` (integração: pendente sai e o atestado órfão sai; dia de lote mantém
  o atestado; analisada, de outra pessoa e de outro escritório não), `ExcluirJustificativaControllerTest` (200, 403 sem
  token e de outra pessoa, 422, 404 de outro escritório, só POST), `AnaliseDaPropriaJustificativaTest` e
  `AnaliseDaPropriaJustificativaControllerTest` (primário e Master analisam a própria; gestor pelo perfil recebe 403 nas
  três rotas e não vê os botões; continua analisando a de outra pessoa; sininho "recusada"), `AbasDoPontoTelaTest`
  (arranjo por filho direto, aba pelo endereço, linhas e situações, o que saiu contando zero, confirmação antes de
  apagar, nomes na ficha), `AbasDoDesenhoCssTest`, `PontoAbasJsTest`, `NomesDasSituacoesDaJustificativaTest`; ajustados:
  `PendenciasDeRegistroTest` (linha nova e lista vazia com texto), `CartaoRegistrarPontoDoDesenhoTest`,
  `EditarJustificativaAnexoControllerTest` (um lápis por dia em análise) e os dois da troca sem recarregar.

**E4. Janelas no molde do desenho.**
- Entra: P44 e P46 (moldura do desenho), P45 e P47 (ficam, com os campos do sistema dentro da moldura nova).
- Depende de: E1.
- Migration: sim (`Version20261109210000`, coluna `motivo` texto nula em `justificativa_ponto`; decisão do Coordenador
  de 09/11/2026). A etapa nasceu sem migration; o "Motivo" obrigatório do desenho (P44 e P46) pediu a coluna.
- Feito (09/11/2026, branch `ponto-desenho-e4`, empilhada na E3):
  - as três janelas da /ponto ("Justificar falta", "Registro de esquecimento" e "Editar justificativa") saíram do modal
    do Bootstrap para a moldura da E3 (`.pe-janela-fundo > form.pe-janela[role=dialog][aria-modal]`, topo azul,
    corpo, linha de erro do desenho entre o corpo e o rodapé, "Cancelar" e a ação), dentro de `.pe-page` (tokens
    `--pe-*` e tema escuro). Esc fecha, clicar fora não fecha, o foco não sai (moldura em `js/ponto-abas.js`, agora
    exposta em `window.PontoAbas`); o comportamento de cada janela mora em `js/ponto-janelas.js`;
  - "Justificar falta" (P44 e P45): "Data da falta" vira o calendário de vários dias do sistema na moldura do desenho
    (abre com hoje do servidor marcado; dia futuro desligado), depois o tipo (os 45 tipos nos 6 grupos, em `<optgroup>`,
    decisão P45 do Coordenador, 09/11/2026; o desenho não tem o campo, então os rótulos e a ordem dos grupos são os do
    sistema, `CategoriaJustificativa`: "Legais (CLT / Legislação)", "Operacionais", "Intercorrências", "Técnicas do
    Sistema", "Situações Críticas" e "Feriados e Regimes Especiais"; a janela "Editar justificativa" usa os mesmos
    grupos), o aviso da falta não justificada, o bloco do esquecimento (horário com segundos), o abono parcial,
    "Motivo" (obrigatório) e "Atestado ou documento (opcional)" (`accept` do servidor: PDF, JPG ou PNG). A conferência
    antes do envio põe a frase na linha de erro: "Informe a data e o motivo." (do desenho) e as do servidor; o alerta
    do navegador do abono com vários dias virou a linha de erro;
  - "Registro de esquecimento" (P46): resumo "dd/mm/aaaa · Saída", "Horário" com segundos (`with_seconds` no
    `JustificativaPontoType`; "14:32" vira 14:32:00) e "Motivo"; "Informe o horário e o motivo." A dica de dentro da
    janela saiu; o aviso flutuante "Enviado para aprovação do gestor." fica para a E8 (P80). `?esquecimento=` continua
    clicando o "+" da folha, agora sem esperar o Bootstrap;
  - "Editar justificativa" (P47): os campos de hoje na moldura nova, mais o "Motivo" (a edição recusa motivo em branco
    ou longo demais; pedido sem o campo não mexe no motivo). O horário do esquecimento na edição segue em minutos: com o
    mesmo tipo de batida e o mesmo H:i do gravado, o horário gravado fica intacto, com os segundos (editar só o motivo
    não troca 14:32:10 por 14:32:00); só um H:i novo é regravado, com :00 (revisão da E4). O motivo editado vai para
    todos os dias em análise do mesmo lote (mesmo `batchId`, mesma pessoa e mesmo escritório); dia já analisado do lote
    guarda o motivo dele (revisão da E4). Risco residual aceito (re-revisão da E4): sem troca de anexo, a edição trava
    só a linha editada; se o gestor analisar outro dia do lote no mesmo instante, esse dia pode ficar com o motivo novo.
    Muda só o texto (o UPDATE leva só a coluna `motivo`): situação, abono e horas ficam como o gestor deixou. Com troca
    de anexo não há corrida (o lote todo pendente é conferido sob trava). Na ficha, o resumo do lote mostra o motivo do
    1º dia, como já mostrava o tipo. O lápis abre por delegação (`data-justificativa-editar`), sem `onclick`;
  - o motivo aparece na aba "Minhas Justificativas" abaixo do tipo (L184) e, na ficha do gestor, uma linha "Motivo" no
    resumo do lote (decisão da implementação: quem analisa precisa ver o que foi escrito). Os parciais da ficha
    (`_justificativa_calendario`, `_justificativa_campos`) ficaram intactos;
  - achado da revisão da E3: depois da edição (salva ou recusada), a tela volta na aba "Minhas Justificativas", no mês
    da justificativa (`PontoController::voltaDaEdicao`, `?competencia=AAAA-MM&aba=justificativas`);
  - motivo: até 2000 caracteres (`JustificativaPontoType::MOTIVO_MAXIMO`), sem os espaços das pontas, o mesmo em cada
    dia do lote. Um motivo "0" aparece (o teste é `is not empty`, na aba e na ficha);
  - desvio necessário: a janela da falta, com o calendário, passa da altura da tela (cerca de 780px; em 1366x768 e no
    celular o topo sumia sem rolagem). A janela usa `margin: auto` no lugar do `margin: 0`: centraliza quando cabe e o
    fundo rola quando não cabe. Os outros valores da moldura seguem o desenho.
- Arquivos: `_justificativa_form`, `_justificativa_esquecimento_modal`, `_abas_justificativas`, `index.html.twig`,
  `tenant/_justificativas_tab`, `public/js/ponto-abas.js`, `public/js/ponto-janelas.js` (novo),
  `public/css/ponto-eletronico.css` (campos das janelas e calendário), `JustificativaPonto`, `JustificativaPontoType`,
  `PontoController` (motivo, segundos, `voltaDaEdicao`), a migration e o e2e `ponto-atalho-cabecalho.spec.js`.
- Testes: `JanelasDoPontoTelaTest` (moldura por filho direto nas três janelas, nada do Bootstrap, campos e ordem,
  motivo na aba e no lápis, motivo de outro escritório não aparece, `?esquecimento=` com o "+", motivo na ficha),
  `MotivoDaJustificativaControllerTest` (motivo em cada dia do lote; sem, em branco e longo: nada nasce; segundos;
  edição grava, recusa e volta na aba; de outra pessoa: 403; edição só do motivo guarda os segundos; motivo editado vai
  aos dias em análise do lote, não ao dia abonado nem ao lote de outra pessoa ou de outro escritório; recusa não grava
  em dia nenhum), `PontoJanelasJsTest`, `JanelasDoDesenhoCssTest` (inclui o `margin: auto` da janela);
  `JanelasDoPontoTelaTest` confere os 6 grupos por filho direto (`select > optgroup[label] > option`) nas duas janelas;
  ajustados com o motivo e a volta nova: `BatidaEmHorarioFuturoControllerTest`, `JustificativaEsquecimentoControllerTest`,
  `PendenciasDeRegistroTest`, `NovaJustificativaComAnexoControllerTest`, `EdicaoDeJustificativaAnalisadaControllerTest`,
  `EditarJustificativaAnexoControllerTest`.

### Bloco 2: gestão sobre os dados de hoje

**E5. Aba Equipe.**
- Entra: P54, P55, P57, P56 (a: a coluna Escala mostra a jornada atual; trocar ali grava uma versão nova da jornada,
  valendo a partir de hoje, com as 4 escalas do desenho como modelos que preenchem os blocos), D16. P58 não entra: quem
  gere o ponto pelo perfil continua vendo Equipe, Auditoria e Configurações, com o primário e o Master.
- Depende de: E3.
- Migration: não.
- Arquivos: controller e UseCase novos em `src/Ponto/` (situação da equipe por escritório; abono em lote chamando a regra
  do abono de hoje, uma por uma). Frente: leitura da jornada vigente e chamada a `SalvarVersaoDaJornadaUseCase` para a
  troca de escala (avisar e combinar antes).
- Risco: ALTO (ponto) e MÉDIO (permissão).
- Testes: unit da situação (em jornada, intervalo, sem registro, encerrada); funcional 403 para colaborador; quem tem a
  permissão no perfil vê a aba; isolamento por escritório; abono em lote não abona justificativa de outro escritório, e
  a própria só para o primário e o Master (P43 a); trocar a escala grava versão nova a partir de hoje, não muda os dias
  anteriores e aparece no histórico da ficha.
- Decisões da etapa (implementada em 09/11/2026, branch `ponto-desenho-e5`, sobre o contrato da E5 e E6; decisões do
  Coordenador na delegação):
  - o painel (`EquipeDoPontoController::painel`, `ConsultarEquipeDoPontoUseCase`) é buscado quando a aba abre
    (`ponto-paineis.js`); a guarda do contrato fica (escritório da sessão, módulo ponto, `GestaoDoPonto::veEquipe`; sem
    ela, 403). A nota "Dados de exemplo" (L211) não entra (D16);
  - equipe: vínculos ativos do escritório da sessão com a chave do ponto ligada, por nome. Quem vê vem primeiro, com o
    subtítulo "Você"; os outros, com o nome do cargo (sem cargo, sem subtítulo);
  - situação de hoje (P54, regra do desenho sobre as batidas que a folha escolhe para o dia, `SituacaoDeHojeNaEquipe`):
    sem entrada, "Sem registro"; com saída, "Encerrada"; repouso sem retorno, "Em intervalo"; senão, "Em jornada".
    Entrada "HH:MM"; último registro "HH:MM · Tipo"; sem batida, "Sem registro" nas duas colunas (seção 8). As batidas
    de hoje do escritório vêm numa consulta só (`RegistroPontoRepository::batidasDoDiaDaEquipe`);
  - Banco: o mesmo número do topo da /ponto da pessoa (`FolhaPontoBuilder::calcularSaldoAnual`, mesmos feriados, jornada
    do escritório e início da contagem). Pendências: as de registro da pessoa neste escritório
    (`ListarPendenciasDeRegistroUseCase::soNoEscritorio`). O cálculo do banco é a parte cara (cerca de 30 consultas
    por pessoa, ver "Custo do painel" abaixo): por isso o painel só é buscado quando a aba abre;
  - 5 indicadores, na ordem do desenho: Em jornada, Em intervalo, Sem registro, Pendências (a soma da coluna) e A
    analisar (o tamanho da lista);
  - Escala (P56 a): as 4 escalas do desenho (`CatalogoDeEscalas`, ids e rótulos do `ESC`, L409). Vem marcada a que tem
    os mesmos minutos em cada dia que a jornada de hoje (a pessoal com blocos ou, sem ela, a do escritório; o horário não
    conta). Nenhuma igual: entra antes uma opção marcada e desligada, "Personalizada (Xh)" ou "Jornada do escritório
    (Xh)", com a carga semanal da própria jornada (sem ela, a soma dos dias; "42h30" quando tem minutos);
  - trocar a escala: POST JSON `/ponto/equipe/{id}/escala` (`ponto_equipe_escala`, token `X-CSRF-Token` da página),
    `TrocarEscalaNaEquipeUseCase`: a pessoa precisa ter vínculo ativo no escritório (senão 404), a escala precisa ser do
    catálogo (senão 422), e grava pelo `SalvarVersaoDaJornadaUseCase` uma versão valendo a partir de hoje com o modelo
    (entrada 09:00, repouso 12:00, retorno 13:00, saída pela carga do dia: 17:20, 18:48, 18:00 ou 16:00). O alerta de
    ponto fica como está na jornada pessoal (sem ela, ligado). A versão que já começa hoje é atualizada; a primeira nasce
    sem cobrir os dias de antes. A conferência de choque com outro escritório é a da ficha, extraída para
    `ChoqueDaJornadaProposta` (a ficha passa a usá-la, mesmo comportamento); na aba, choque responde 409 com a mensagem
    (a ficha segue com 422). Na tela: deu certo, "Escala de NOME alterada." só para leitor de tela; recusa ou sem
    resposta (prazo de 15 s), a frase no topo do painel (`role="alert"`) e a escolha volta. O painel não é buscado de
    novo depois da troca;
  - "Justificativas a analisar (N)" (P57): as em análise do escritório, de quem tem vínculo ativo nele
    (`JustificativaPontoRepository::aAnalisarNoEscritorio`). As de quem vê entram só quando ele pode analisar a própria
    (P43 a, `AnaliseDaPropriaJustificativa`) e vêm primeiro; depois as da equipe, da data mais nova para a mais antiga e
    por nome. O subtítulo é o da E3, num macro só (`_justificativa_macros.html.twig`, a saída da E3 não muda). O macro
    sai sem espaço nas pontas e já escapado: quem usa imprime como veio, sem `|trim`, e o `data-info` da aba "Minhas
    Justificativas" é montado por bloco (`{% set %}` e `{% endset %}`), não com `~`, para não escapá-lo de novo. O número
    da aba é `JustificativasAAnalisarDaEquipe::contarAAnalisar(User, Tenant): int` (uma contagem, sem carregar a lista);
  - "Abonar selecionadas" é um formulário (POST `/ponto/equipe/abonar`, `ponto_equipe_abonar`, `ids[]`, token
    `ponto_equipe_abonar`) que volta para a /ponto na aba Equipe. Desligado sem nada marcado (o JS liga). O lote
    (`AbonarJustificativasDaEquipeUseCase`) passa cada id pelas portas da ficha: só do escritório da sessão e de quem tem
    vínculo ativo ("Justificativa não encontrada neste escritório."), a própria só com o P43 a; o abono em si é o da
    ficha, pela interface `AbonoDeJustificativa` (uma função: abona a justificativa já conferida, ou devolve o motivo
    de não ter abonado). Mensagens: "1 justificativa abonada." ou "N justificativas abonadas." e uma por recusa
    ("Nome (dd/mm/aaaa): motivo"); nada marcado, "Selecione ao menos uma justificativa.";
  - ligado na integração (09/11/2026): `AbonoPelaAnaliseDaJustificativa` chama
    `AnalisarJustificativaUseCase::abonarPorId` (E9); a recusa dele (`AnaliseDeJustificativaRecusadaException`) vira o
    motivo da frase; cada justificativa grava na própria transação, então uma recusa não desfaz as outras;
  - do sistema, no molde do desenho: a linha "Ninguém registra ponto neste escritório." na tabela vazia, o botão
    desligado mais claro, a frase de erro no topo e o aviso só para leitor de tela. A tabela não tem `pe-resp`: no
    celular rola para o lado. CSS num bloco só no fim de `ponto-eletronico.css`, com os tokens da tela (sem token novo);
  - o número de Banco não tem regra própria na aba: é o do motor do saldo, o mesmo do topo da /ponto da pessoa
    (`EquipeDoPontoTelaTest::testBancoEPendenciasComoNaPontoDaPessoa`). Decisão do Coordenador (09/11, 22h05, §10.4): o
    banco usa a mesma jornada da folha, e sem versão pessoal os dias contam pela jornada do escritório; quem fizer essa
    troca é a E17 (`BancoDeHorasPorLotes::calcular`), e quem integrar por último na ponto-desenho troca a chamada da aba;
  - Custo do painel (revisão de 09/11/2026, Coordenador): cerca de 30 consultas por pessoa, quase todas do
    `calcularSaldoAnual`, que faz 2 por mês do ano corrido. Medido no `EquipeDoPontoConsultasTest` (1 pessoa contra 3,
    no caso caro: jornada pessoal, batida desde o ano passado e batida nos últimos 30 dias, que faz as pendências lerem
    os feriados e os esquecimentos): 31 por pessoa em novembro, 9 fixas mais 2 por mês (33 em dezembro). O teste trava esse teto; subir o teto pede rever este item antes. Em produção, em 09/11/2026, o maior
    escritório tem 7 pessoas que registram ponto: umas 210 consultas, bem dentro do prazo de 20 s do carregador.
    **Mudou na E17:** o banco da coluna é o `BancoDeHorasPorLotes` (o mesmo número do topo da /ponto, desde o início da
    contagem), que lê os meses todos de uma vez: 12 consultas por pessoa, sem crescer com os meses (teto 12). Sem
    limite de pessoas no painel por enquanto; rever quando um escritório passar de 30 pessoas no ponto. Duas sobras
    pequenas ficam anotadas: os feriados relidos por pessoa nas pendências (`ListarPendenciasDeRegistroUseCase`, que a
    E6 mexe; fica para depois dela) e o `historicoDe` lido duas vezes por pessoa (no banco e na escala);
  - Abono em lote (revisão de 09/11/2026): cada justificativa é abonada e auditada uma por uma, com o registro
    "Justificativa abonada" da E9; não há um evento único "Abono em lote" (dc L818, D5). Uma falha inesperada no meio
    do lote deixa gravadas as que vieram antes dela (o lote não abre transação). A recusa esperada do abono da ficha
    (`AnaliseDeJustificativaRecusadaException`, do AnalisarJustificativaUseCase da outra frente) volta como motivo pelo
    `AbonoDeJustificativa` quando o adaptador for ligado, vira mensagem e o lote segue;
  - limite conhecido, versão futura agendada (revisão de 09/11/2026): se a pessoa já tem uma versão da jornada que
    começa depois de hoje, a escala trocada na aba vale só até o dia anterior a essa versão, e a aba não avisa.
- Arquivos: `EquipeDoPontoController`, `ConsultarEquipeDoPontoUseCase`, `TrocarEscalaNaEquipeUseCase`,
  `AbonarJustificativasDaEquipeUseCase`, `src/Ponto/Service/` (`CatalogoDeEscalas`, `EscalaDoCatalogo`,
  `EscalaDaPessoaNaEquipe`, `HojeDaPessoaNaEquipe`, `JustificativasAAnalisarDaEquipe`, `ChoqueDaJornadaProposta`,
  `AbonoDeJustificativa` e o provisório), `SituacaoDeHojeNaEquipe`, DTOs de saída, exceções `ForaDaEquipeException` e
  `EscalaDesconhecidaException`; consultas novas em `RegistroPontoRepository`, `JustificativaPontoRepository` e
  `UserTenantRepository` (legado, só acrescenta); `ListarPendenciasDeRegistroUseCase::soNoEscritorio`;
  `JornadaColaboradorController` (usa `ChoqueDaJornadaProposta`); `_abas_equipe.html.twig`,
  `_justificativa_macros.html.twig`, `_abas_justificativas.html.twig` (usa o macro); `public/js/ponto-equipe.js`; bloco
  da E5 em `ponto-eletronico.css`.
- Testes: `SituacaoDeHojeNaEquipeTest`, `CatalogoDeEscalasTest`, `TrocarEscalaNaEquipeUseCaseTest`,
  `AbonarJustificativasDaEquipeUseCaseTest` (com o dublê do abono e o provisório), `EquipeDoPontoTelaTest` (arranjo por
  filho direto e textos, quem entra, situações, escala marcada, banco e pendências iguais aos da /ponto da pessoa,
  gestor pelo perfil, lista vazia, outro escritório), `TrocarEscalaNaEquipeTest` (versão de hoje, passado sem mudança
  com e sem jornada pessoal, o banco antes e depois da primeira troca, duas trocas no mesmo dia, histórico na ficha,
  choque 409, duas trocas ao mesmo tempo 409, guardas, vínculo suspenso e quem saiu 404),
  `EquipeDoPontoSubtituloTest` (o macro sem espaço nas pontas; subtítulo com `&` escapado uma vez nas duas abas),
  `EquipeDoPontoConsultasTest` (teto de consultas por pessoa),
  `AbonoEmLoteDaEquipeTest` (dublê do abono: abonadas, recusa, isolamento, P43 a no lote, guardas),
  `EquipeDoDesenhoCssTest`, `PontoEquipeJsTest`.

**E6. Configurações, parte 1: sedes e feriados na aba.**
- Entra: P68, P69 (fica: mapa e busca por CEP ou endereço), P70 (a: sede nova nasce com 150 m; as sedes de hoje não
  mudam), P71 (abrangência por sede), P72 (fica), D4, D13, D21.
- Depende de: E3.
- Migration: sim (coluna sede opcional no feriado, P71).
- Arquivos: rotas de sedes de hoje (`TenantController.php`, avisar), `FeriadoController.php`, `CalculadoraJornada`
  (feriado por sede).
- Testes: feriado por sede vale só para quem é daquela lotação; sede sem coordenada continua ignorada; confirmação antes de
  remover (D4); sede nova com 150 m e as que já existem com o raio delas.
- Decisões da etapa (implementada em 09/11/2026, branch `ponto-desenho-e6`):
  - **Desvio necessário do P71 (decidido pelo Coordenador, 09/11/2026):** a abrangência do feriado é "Todas as sedes" ou
    uma LOTAÇÃO do escritório, não uma sede. O desenho casa a sede do feriado com a lotação da pessoa (inventário 6.2,
    "casado com a lotação da pessoa em caixa alta"); no sistema a pessoa não tem sede, tem lotação (`user_tenant`).
    Migration `Version20261109200000`: `feriado.lotacao_id` opcional, ON DELETE CASCADE (apagar a lotação apaga os
    feriados só dela; SET NULL os faria valer para todos e mudaria o saldo de quem nunca os teve). Os feriados que já
    existem ficam sem lotação: nenhuma folha muda;
  - a regra mora num ponto só, `FeriadosDaPessoa`: sem lotação vale para todos; com lotação, só para quem tem aquela
    lotação no escritório DO FERIADO (vínculo ativo ou não: a folha de quem saiu continua contando o feriado que valia
    para ela). Sem N+1: nenhuma consulta sem feriado por lotação; com ele, uma por pessoa e escritório, guardada até o
    fim do request ou da mensagem do worker (`kernel.reset`). Aplicada no `FolhaPontoBuilder::buildRows` (folha na
    tela, ficha do colaborador, PDF, XLSX, saldo até o mês e do ano, horas pagas, "Regularizar ponto de ontem", tudo
    passa por ele) e em `ListarPendenciasDeRegistroUseCase` (lê o feriado sem a folha). O comando dos nacionais (D21)
    só conta o feriado sem lotação ao ver se a data já está cadastrada;
  - Agenda (revisão da E6): na grade (`DatasDoSistemaController`), o feriado de uma lotação só aparece para quem é
    dela, pelo ID da lotação do vínculo de quem vê (nunca pelo nome); o de "Todas as sedes" aparece para todos; quem
    está sem lotação só vê os de "Todas as sedes". A regra mora em `ListarDatasDoSistemaUseCase` (parâmetro
    `idDaLotacao`). A sincronização com o Google (`DatasDaAgendaParaOGoogle`) é POR PESSOA (uma conexão por pessoa e
    escritório, na conta dela) e aplica a mesma regra de quem vê. O dia útil do escritório (`DiaUtilDoEscritorio`:
    cobrança do checklist e resumo diário) é a regra do escritório inteiro e só considera os feriados sem lotação: um
    feriado de Maceió não pula o dia do escritório;
  - a meta do dia no cartão "Registrar Ponto" (`PontoController::index`, que hoje lê todos os feriados do escritório:
    o feriado de outra lotação zera a meta do dia na tela, não o saldo) entra no commit do orquestrador que mexe no
    `PontoController`, que não é desta etapa;
  - limite conhecido: o feriado apagado pela cascata da lotação (ON DELETE CASCADE) não vai ao `audit_log`, porque
    quem apaga é o banco, sem passar pelo Doctrine. Só a remoção da lotação fica registrada;
  - seções por permissão: "Sedes e raio de registro" só com `admin.ponto.manage`, "Feriados do escritório" só com
    `admin.tenant.settings.manage` (as mesmas das páginas de hoje). Rotas novas no domínio Ponto
    (`ponto_configuracoes_sede_criar`, `_sede_salvar`, `_sede_remover`, `_feriado_criar`, `_feriado_remover`), JSON:
    escritório da sessão, módulo do ponto e permissão da seção (403), token da página (403), posse (a sede, o feriado e
    a lotação de outro escritório: 404). UseCases `SalvarSedeDoPontoUseCase`, `RemoverSedeDoPontoUseCase`,
    `CadastrarFeriadoDoPontoUseCase`, `RemoverFeriadoDoPontoUseCase`;
  - sedes (P68, D13): a linha inteira grava ao sair do campo (o desenho grava a cada tecla), uma gravação por linha de
    cada vez; o erro aparece na linha de baixo. Latitude e longitude aceitam vírgula (só na aba; a página antiga segue
    recusando). Mesmas validações e frases da página antiga, numa regra só (`RestricoesDaSede`, usada também pelo
    `SedeType`), mais "Informe o nome da sede.". "Adicionar sede" acrescenta "Nova sede" no navegador; ela só vai
    para o banco com latitude e longitude válidas (a coluna é obrigatória), então a cerca nunca recebe sede sem
    coordenada (e continua ignorando, se receber). "Usar minha posição" usa o leitor com prazo do módulo da batida, 6
    casas; sem posição, "Localização indisponível neste aparelho.";
  - P70 a: `Sede::RAIO_PADRAO = 150` é o padrão da entidade e do campo vazio do `SedeType`: a sede nova nasce com 150 m
    na aba e na página antiga; as que já existem ficam com o raio delas (nada no banco muda);
  - P69: a página "Sedes do ponto" fica, com o link "Mapa e busca por endereço" no cabeçalho da seção (molde do botão
    de contorno do desenho). P72: "Repete todo ano" no formulário (marcada, como na página "Feriados e Folgas") e a
    marca "Todo ano" na lista; a data do que repete aparece dd/mm, a do pontual dd/mm/aaaa. A página "Feriados e Folgas"
    fica; editar nela não toca a abrangência (`FeriadoType` não tem o campo). A lista mostra todos os feriados do
    escritório, inclusive os nacionais carregados pelo comando (D21);
  - D4: remover sede e remover feriado abrem a janela na moldura do desenho (`role="dialog"`, Esc fecha, o foco não
    sai); o "Remover" da janela grava. Remover a sede segue a regra da página antiga (as batidas dela ficam sem a sede,
    com o nome guardado), e o aviso conta quantas. A "Nova sede" nunca gravada sai sem perguntar;
  - aviso flutuante do desenho (§2.13) só nesta seção (`.pe-sf-aviso`), com a subida que mantém a centralização (o
    `bjpUp` do desenho desfaz o `translateX(-50%)` durante a animação). Comportamento em
    `public/js/ponto-configuracoes-sedes.js` (o `ponto-configuracoes.js` é da E7), carregado pelo index logo depois
    dele; CSS no bloco "Sedes e feriados" de `ponto-eletronico.css`, só com os tokens `--pe-*` que já existiam.
- Arquivos da etapa: `Feriado` (lotação), `Sede` (`RAIO_PADRAO`), `SedeType`, `FeriadosDaPessoa`, `FolhaPontoBuilder`,
  `ListarPendenciasDeRegistroUseCase`, `SeedFeriadosNacionaisCommand`, `ConfiguracoesDoPontoController`, os quatro
  UseCases, `SedesEFeriadosDoPonto`, `RestricoesDaSede`, os DTOs `SedeDoPonto*` e `FeriadoDoPonto*`,
  `ConfiguracaoDoPontoRecusadaException`, `templates/ponto/_config_sedes.html.twig`, `_config_sede_linha.html.twig`,
  `_config_feriados.html.twig`, `_config_feriados_lista.html.twig`, `_abas_configuracoes.html.twig` (os dois includes),
  `index.html.twig` (uma linha de script). Na revisão: `ListarDatasDoSistemaUseCase`, `DatasDoSistemaController`,
  `DatasDaAgendaParaOGoogle`, `DiaUtilDoEscritorio` (feriado da lotação na Agenda), `FeriadoController::index` e
  `feriado/index.html.twig` (coluna "Abrangência" na página "Feriados e Folgas"). `TenantController` não muda.
  Atributos do JS próprios da etapa: `data-pc-sede-*`, `data-pc-feriado-*` e `data-pc-sf-*` (as janelas); o
  `data-config-*` fica livre para a E7. Raio vazio na sede que já existe mantém o raio dela; a nova nasce com 150 m.
- Testes da etapa: `FeriadosDaPessoaTest`, `FeriadoPorLotacaoTest` (folha, saldo e pendências de duas pessoas em
  lotações diferentes; "Todas as sedes"; feriado recorrente da lotação; lotação de outro escritório com o mesmo nome),
  `ListarDatasDoSistemaUseCaseTest`, `DatasDoSistemaControllerTest`, `SincronizacaoDeDatasComGoogleTest` e
  `DiaUtilDoEscritorioTest` (a regra na Agenda, no Google e no dia útil), `FeriadoAbrangenciaNaPaginaAntigaTest`,
  `MigracaoDoFeriadoPorLotacaoTest`,
  `SeedDosNacionaisComFeriadoPorLotacaoTest`, `ConfiguracoesDoPontoSedesEFeriadosTest` (arranjo por filho direto e
  textos, seções por permissão, 150 m na nova e na página antiga, raio das existentes, validações, remover com as
  batidas desvinculadas, 403 por seção, sem token e com permissão só em outro escritório, 404 entre escritórios,
  editar na página antiga preserva a lotação), `SedesDaCercaDoPontoTest`, `SedesEFeriadosDoDesenhoCssTest`,
  `PontoConfiguracoesSedesJsTest`; o construtor novo nos testes da folha.

### Bloco 3: motor novo (cada um atrás da sua chave, nesta ordem)

**E7. Liga e desliga.**
- Entra: P67, P94, P95, P96 (desenho: cada chave nasce ligada quando a sua etapa ficar pronta) e P93 (decidido: usa a
  página "em preparação" e a faixa que o sistema já tem). Nesta etapa entram a chave da tela `ponto`, a de `ponto-geo`
  (a cerca já existe) e a estrutura das duas camadas (Global e Escritório); as outras chaves entram nas etapas das suas
  funções (E8, E11, E15, E16, E18), para o Master não ligar função que ainda não existe.
- Depende de: nada (pode correr junto do bloco 1).
- Migration: sim (configuração do Ponto por escritório). O catálogo do Master não precisa (chave sem linha vale ligada).
- Arquivos: `CatalogoDeRecursos.php` (tela `ponto` e `ponto-geo`); guarda de rota da tela: as rotas globais
  (`/ponto/estado-do-dia`, `/ponto/alerta-horario`, `/ponto/pendencia-do-dia`) e a página pública de validação não podem
  cair junto com a tela. Nada da frente (não engordar `JornadaTenant`, que é do PR 3 dela).
- Testes: Global desligado trava o Escritório; tela desligada mostra a página "em preparação" do sistema
  (`templates/recurso/em_preparacao.html.twig`) para quem não é Master e a faixa vermelha para o Master; chave nova nasce
  ligada (P96); a aba Configurações aparece para quem gere o ponto pelo perfil (P58 não).
- Decisões da etapa (implementada em 09/11/2026, branch `ponto-desenho-e7`, contrato do Coordenador):
  - catálogo: tela `ponto` (Telas, "Ponto Eletrônico", antes do Voto) com o prefixo `ponto_`, e função `ponto-geo`
    (Funções, "Ponto: geolocalização"), sem linha em `recurso_estado` (nascem ligadas). Ficam abertas com a tela
    desligada só `ponto_estado_do_dia`, `ponto_alerta_horario` e `ponto_pendencia_do_dia`; a batida cai com a tela;
  - a rota `master_recurso_alternar` aceita chave com hífen (`[a-z][a-z-]{0,39}`); as chaves sem hífen continuam iguais;
  - menu e atalho do avatar: o item do Ponto segue `recurso_visivel('ponto')` e `recurso_marca('ponto')`, como o Voto;
    com a tela desligada o atalho mostra o estado do dia sem o botão de registrar (e sem o "Regularizar ponto de
    ontem", que leva à tela fechada);
  - tabela `ponto_config_escritorio` (Version20261109180000, entidade `ConfiguracaoDoPonto`): uma linha por escritório,
    criada no primeiro salvamento (o repositório a garante com `INSERT ... ON CONFLICT (tenant_id) DO NOTHING`, vazia e
    com os padrões, e só depois a trava com `SELECT ... FOR UPDATE`: dois primeiros salvamentos ao mesmo tempo não
    duplicam a linha nem dão erro; a linha vazia vale o mesmo que não ter linha e não entra na trilha, a mudança
    entra); mapa `chaves` (chave ausente vale ligada), `raio_padrao_metros` 150
    (P70), `banco_limite_horas` 120 e `banco_prazo_meses` 6 (P73; a faixa de 1 a 24 é da E17) e
    `permite_trabalho_externo` ligado (P74), com os padrões em constantes da entidade; nenhuma tela edita os ajustes
    na E7. Entra na purga do escritório;
  - `ponto-geo` desligada (Global ou Escritório): a batida não exige posição nem confere sede, não grava sede e não
    vira home office; com a geolocalização desligada, a posição é descartada (latitude, longitude e precisão
    ficam vazias, como no home office: o escritório escolheu não usar localização). A tela e o atalho não pedem a
    posição e a caixa da localização some (`geoLigado` no estado do dia e na tela);
  - tela `ponto` fechada para quem vê (desligada e quem vê não é Master, o mesmo critério do guarda de rota): o
    cartão diário de pendência não sai, porque o link dele leva à /ponto em preparação;
  - chave "Escritório" recusada porque o Master desligou o Global depois que a aba carregou: a resposta 422 leva
    `global: false` e a tela trava a chave na hora (`js/ponto-configuracoes.js`).

**E8. Tela cheia, aviso flutuante, sons, vibração e lembrete.**
- Entra: P79 a P83 (e P84 a P86, que ficam). As chaves `ponto-animacao`, `ponto-sons` e `ponto-lembrete` entram no
  catálogo aqui, ligadas (P96).
- Entra também o que a E1 deixou na caixa de hoje: a tela vermelha do erro do GPS (P10 b) e a parte do desenho do P11
  (resultado em tela cheia, P79, e aviso flutuante, P80, no lugar de `#batida-aviso`), e o botão de som e o de mudo
  (P81) no rodapé do cartão.
- Depende de: E1, E7.
- Migration: não (o mudo fica guardado no aparelho, como no desenho).
- Arquivos: JS e CSS da tela. Nada da frente.
- Testes: funcional das fases por classe e `aria`; movimento reduzido; com a chave desligada o resultado vira aviso
  flutuante; o áudio do alerta de horário continua liberado.

**E9. Trilha de auditoria encadeada.**
- Entra: P64, P66 (fica). D5 (não): a trilha leva o que o desenho grava (inventário §2.11): batidas, ajustes,
  justificativas (envio, exclusão, análise, abono em lote), envio da fila sem internet, folha emitida e assinada, escala
  alterada, as chaves de função (Global e Escritório) e os eventos da facial. Mudanças de sede, feriado, limite, prazo,
  modo facial e foto ficam só no `audit_log`, que continua.
- Depende de: E7.
- Migration: sim (tabela da trilha).
- Arquivos: serviço novo de trilha; chamadas nos fluxos de batida, justificativa, análise e chaves; cada etapa seguinte
  chama a trilha nos eventos dela. Frente: a escala alterada (o desenho grava "Escala alterada") toca os 3 UseCases de
  versão (avisar).
- Chave do selo (decidido pelo Coordenador em 09/11/2026): o selo é HMAC com a chave da finalidade "trilha", derivada
  do segredo único `PONTO_HMAC_SECRET` (seção 10, "Chaves por finalidade"). Cada linha guarda a versão da chave. Com o
  segredo vazio, a etapa segue a regra da seção 10 (a trilha grava sem selo e a conferência diz que a cadeia não foi
  selada); a confirmar ao abrir a E9.
- Testes: dois eventos ao mesmo tempo no mesmo escritório mantêm a cadeia; adulterar uma linha acusa "a cadeia foi
  alterada"; escritórios não se misturam; purga do escritório apaga a cadeia dele; mudança de sede ou feriado não entra
  na trilha e continua no `audit_log`.
- Decisões da etapa (implementada em 09/11/2026 na branch `ponto-desenho-e9`, a integrar na `ponto-desenho`):
  - tabela `ponto_auditoria` (migration `Version20261109190000`): sequência por escritório a partir de 1 com índice
    único (escritório, sequência), índice (escritório, em), FK do escritório com CASCADE; `autor_id` SEM FK (um
    `SET NULL` mudaria coluna selada e quebraria a cadeia; o `audit_log` também guarda o id solto) e `autor_nome`,
    retrato do momento;
  - `TrilhaDoPonto::registrar(Tenant, ?User, string $acao, array $detalhe)`: grava por DBAL (nunca dá `flush()` em quem
    chama), na transação de quem chama ou numa própria; `pg_advisory_xact_lock(4203, escritório)` antes de ler o último
    elo, a última trava do fluxo; `emTransacao()` para quem grava o fato com `flush()` solto (batida, escala);
  - selo: HMAC-SHA256 com a chave `hash_hkdf('sha256', PONTO_HMAC_SECRET, 32, 'ponto-trilha')`
    (`ChavesDoHmacDoPonto`, com `disponivel()`, `versaoAtual()` e `chave()`, reaproveitável pela E15 e E16), sobre o
    JSON canônico de selo anterior, escritório, sequência, em, autor (id e nome), ação e detalhe; a linha guarda a versão
    da chave. `PONTO_HMAC_SECRET=` declarado vazio no `.env`; vazio grava sem selo e sem versão;
  - elo: o `selo_anterior` de cada linha é o ÚLTIMO SELO da cadeia antes dela (o da linha de antes, ou o último que
    existia, se ela entrou sem selo); a primeira selada depois de um trecho sem selo se prende à última selada antes
    dele. A `TrilhaDoPonto` grava assim e a `ConferenciaDaCadeiaDoPonto` confere assim;
  - conferência (`ConferirTrilhaDoPontoUseCase`): íntegra; alterada (com a sequência do primeiro evento que não confere:
    coluna mexida, linha apagada, elo trocado, selo apagado no meio, versão sem chave; quando o elo quebra logo depois de
    linhas sem selo, o número apontado é o primeiro delas); trecho sem selo (eventos sem selo, por exemplo os gravados
    antes do segredo ou numa falta temporária dele, com as faixas dos números: "Os eventos nº X a Y estão sem selo e não
    podem ser conferidos.", no singular "O evento nº N está sem selo e não pode ser conferido.", na cor de alerta, nunca
    o verde de íntegra; o texto não afirma a origem, que o sistema não conhece); ou não selada (o servidor sem o
    segredo). A chave sai da versão gravada em cada linha;
  - limites aceitos da conferência (revisões de 09/11/2026; não há âncora fora do banco): apagar os últimos eventos não
    é detectado (não há linha depois para quebrar o elo); qualquer fim da cadeia com o selo tirado (e o conteúdo
    trocado), ou um evento sem selo acrescentado no fim, aparece como trecho sem selo e não como alterada, e continua
    assim depois dos eventos seguintes; zerar os selos de todas as linhas (selo e selo anterior) aparece como trecho sem
    selo, ou não selada sem o segredo. O meio selado continua protegido: alteração, remoção e inserção dão alterada. O
    aviso nunca fica verde. A conferência percorre a cadeia inteira a cada abertura da aba (achado 9 da revisão), a
    reavaliar se ficar lenta;
  - eventos: batida (inclui o atalho do avatar), justificativa enviada, editada (com ou sem troca do atestado) e
    excluída pelo colaborador, abonada, recusada, voltou para análise, abono em lote (um evento com as abonadas), lançada
    já abonada pelo gestor (mais uma "batida do esquecimento abonado" por batida que o lançamento cria, o mesmo evento da
    análise), batida do esquecimento abonado e escala alterada (as 3 versões da jornada, autor da sessão);
  - atomicidade provada em teste: se o fato falha depois do evento (horário futuro na batida do esquecimento, dentro do
    abono), o evento sai junto; se a trilha falha, a batida e o abono não ficam gravados;
  - dados pessoais (LGPD, decidido pelo Coordenador na revisão): o `autor_nome` e o nome do colaborador no detalhe ficam
    na trilha depois da exclusão da conta, porque a guarda do registro de ponto é obrigação legal; a trilha sai só com a
    purga do escritório;
  - aba Auditoria no molde das abas de gestão: `GestaoDoPonto::veAuditoria` (`admin.ponto.manage`; primário e Master
    passam), rota `ponto_auditoria` com `?pagina=` (300 por página, como manda o desenho no inventário 2.11, L313; o mais
    recente primeiro), parcial `_abas_auditoria.html.twig`, botões de página por `js/ponto-auditoria.js`; CSS no bloco
    "Auditoria (E9)";
  - purga: `ponto_auditoria` na ordem de deleção do `PurgarEscritorioUseCase`;
  - ficam para depois: a chave do Escritório (E7, entra na trilha quando a E7 chegar), os ajustes de batida (E10), a
    fila sem internet, a folha e a facial (cada etapa chama a trilha nos eventos dela). A chave Global do Master fica só
    no `audit_log`, como hoje (`RecursoEstado` é auditável no escopo da plataforma, sem escritório para a cadeia).

**E10. Batida só inserção, com ajuste.**
- Entra: P65 (a: "Editar" e "Excluir" da ficha viram "Ajustar" e "Desconsiderar"), P29, D18 (o esquecimento abonado
  vira ajuste), P66 (o "desfazer" da auditoria geral não troca nem apaga batida).
- Depende de: E9.
- Migration: sim (tabela de ajuste).
- Arquivos: `TenantController.php` (editar e excluir batida), `RegistrarBatidaDoEsquecimentoUseCase`, reverter abono,
  `EscolhaDasBatidasDoDia`, `FolhaPontoBuilder.php`, `folha_pdf.html.twig` e `FolhaPontoXlsxExporter.php` (marca "aj").
  Frente: `FolhaPontoBuilder.php`, `TenantController.php`, `folha_pdf.html.twig` e o XLSX. Combinar antes.
- Testes: folha antes e depois igual quando não há ajuste; ajuste muda a folha e guarda o valor antigo; reverter desfaz;
  ninguém consegue apagar batida pela tela nem pelo "desfazer" da auditoria; mês com ajuste no PDF e no XLSX.

**E11. Alertas que não bloqueiam.**
- Entra: P21 (a: avisa sem recusar, com "Registrar assim mesmo"; os mínimos vêm da Jornada do escritório). A chave
  `ponto-alertas` entra no catálogo aqui, ligada (P96).
- Depende de: E7.
- Migration: não.
- Arquivos: `PontoController.php` (batida), textos da `_modal_jornada_tenant.html.twig` (frente, PR 3: avisar).
- Testes: retorno com repouso curto entra e a folha marca "não conforme"; entrada antes da interjornada entra com aviso;
  7º dia seguido avisa; o esquecimento continua sem travar (R1 a R4).

**E12. Fora do raio vira trabalho externo; raio com a precisão.**
- Entra: P18 (a: fora do raio, "Registrar como trabalho externo" com local e motivo, para a análise do gestor), P74
  (caixa "Permitir trabalho externo" na configuração do escritório).
- Depende de: E7, E9.
- Migration: sim (batida marcada como trabalho externo, com o motivo; justificativa ligada à batida).
- Arquivos: `PontoController.php`, `SedesDaCercaDoPonto`, `ponto-batida.js`.
- Testes: fora do raio sem a opção do escritório continua recusado; com a opção, entra marcado e cria a justificativa;
  precisão acima do teto pede nova leitura; home office continua sem localização.

**E13. Hora oficial e hora do aparelho.**
- Entra: P5 (a hora do servidor é a hora oficial, ajustada pelo atraso na tela; a hora do aparelho fica guardada só
  como informação), base do P20 (a), D9.
- Depende de: E7. Pendência do dono: conferir o NTP da VPS (P5, seção 10). Não trava: o código já usa o relógio do
  servidor; o comando de conferência vai ao Samuel em bloco para o terminal externo.
- Migration: sim (hora do aparelho, hora de chegada, marca de envio sem internet, identificador único do envio).
- Arquivos: `RegistroPonto`, `PontoController.php`, `ponto-batida.js`.
- Testes: batida grava as duas horas; o mesmo identificador enviado duas vezes não cria duas batidas.
- Decisões da etapa (09/11/2026, Coordenador):
  - migration `Version20261109220000`: em `registro_ponto`, `hora_aparelho`, `hora_chegada`, `enviada_sem_internet`
    (padrão falso) e `id_envio`, com índice único `uniq_registro_ponto_user_envio` em (pessoa, `id_envio`); as linhas
    antigas ficam vazias, sem preenchimento;
  - o envio da batida (`POST /ponto/batida`) ganha `idEnvio` (UUID v4 feito no toque, o mesmo em toda nova tentativa) e
    `horaAparelho` (milissegundos do relógio do aparelho no toque); os dois são opcionais, e sem eles tudo fica como antes;
  - na batida normal, a hora da batida e a de chegada são o mesmo instante do servidor; a do aparelho é só informação e,
    inválida, fica vazia sem recusar;
  - o mesmo `idEnvio` da mesma pessoa no mesmo escritório responde "Esta batida já tinha sido registrada." sem gravar de
    novo; dois envios ao mesmo tempo são barrados pelo índice único e recebem a mesma resposta; o mesmo `idEnvio` de
    outra pessoa grava normal;
  - a leitura dos campos fica em `EnvioDaBatida` (`src/Ponto/Service`), para o `PontoController` mudar o mínimo;
  - a tela e o atalho do cabeçalho mandam os dois campos; o atalho não tem fila.

**E14. Fila sem internet.**
- Entra: P20 (a: vale a hora do aparelho, as duas horas ficam guardadas e a batida aparece marcada "enviada sem
  internet" para o gestor). O teto de idade da batida guardada (exemplo: 72 h) se fecha com o Coordenador ao abrir a E14.
- Depende de: E13.
- Migration: não (usa as colunas do E13).
- Arquivos: `ponto-batida.js` (fila cifrada, envio ao voltar a internet).
- Testes: JS da fila (`node --check` e teste do envio); servidor recusa batida guardada além do teto de idade; a folha e o
  gestor veem "enviada sem internet".
- Decisões da etapa (09/11/2026, Coordenador):
  - teto de idade da batida guardada: 72 horas. O relógio do aparelho mais de 2 minutos adiantado recusa; até 2 minutos
    adiantado, vale a hora de chegada;
  - a fila só existe na tela /ponto (o atalho do cabeçalho não guarda). Fica no IndexedDB, cifrada com AES-GCM 256 e chave
    não extraível, um IV de 12 bytes por entrada, gravação `durability: 'strict'` (`public/js/ponto-fila.js`). Exige
    contexto seguro: no dev, conferir por `http://localhost:8080`;
  - o envio da fila leva `semInternet`, `usuarioId` e `escritorioId`; batida de outra conta ou de outro escritório volta
    409 logo depois da autenticação e continua guardada, com uma linha própria na caixa da fila;
  - todas as regras da batida (home office, cerca, outro escritório, repouso, interjornada, entrada do dia) são avaliadas
    na hora do aparelho;
  - a etiqueta da folha é o texto do desenho, "Sem internet" (comprovante, L584);
  - a recusa do servidor tira a entrada da fila e a guarda, cifrada, numa loja própria (`recusas`), na mesma transação.
    A tela mostra a recusa com o tipo e a data, e só então a apaga. Entrada que não abre ou com mais de 7 dias sai do
    aparelho;
  - a batida da fila recebe o NSR da E15: a ligação e o teste ficam com a E15.
- Limites conhecidos:
  - a batida da fila é medida pela jornada de hoje (regra que já existia);
  - os testes da fila no CI leem o código. O comportamento no IndexedDB foi rodado no node pelo implementador e pela
    revisão, fora do CI;
  - a fila permite registrar até 72 horas para trás, só com a marca "Sem internet" (decisão do P20);
  - o mesmo `idEnvio` tentado de novo em outro escritório responde "já registrada" no segundo, embora a batida esteja no
    primeiro (caso raro).

**E15. Comprovante, aba Comprovantes e validação.**
- Entra: P48, P49, P50 (nome inteiro, como o desenho; sem CPF nem localização), D2, D9, D20. A chave
  `ponto-comprovante` entra no catálogo aqui, ligada (P96).
- Depende de: E7, E9, E13. Pendência do dono: criar o segredo do HMAC em produção (P34, P48, P50, seção 10).
- Gancho do segredo: o código lê a variável de ambiente `PONTO_HMAC_SECRET` (declarada vazia no `.env` do repositório,
  só o nome). Com ela vazia a etapa entra pronta e não trava: a batida grava normalmente, com o NSR (D2); o comprovante
  não é emitido; a aba Comprovantes e o "Validar código" não aparecem; a página pública responde "Código não
  localizado.". Com a variável preenchida, a emissão começa sozinha.
- Migration: sim (comprovante e contador do NSR por escritório).
- Arquivos: controller novo; rota pública (precisa liberar o caminho em `security.yaml`, arquivo quente: avisar); leitura
  da `PONTO_HMAC_SECRET` na configuração (`app/config/*.yaml`, arquivo quente: avisar); biblioteca do QR local.
- Testes: NSR sem buraco com batidas ao mesmo tempo; código errado e inexistente com a mesma resposta; a página pública
  mostra o nome inteiro e não mostra CPF nem localização; limite de tentativas; com `PONTO_HMAC_SECRET` vazia, a batida
  grava, nada é emitido e nada quebra.
- Decisões da implementação (Coordenador, 09/11/2026):
  - O "LOCAL DO REGISTRO" do desenho sai só na imagem do comprovante, que a própria pessoa baixa; a página pública
    continua sem localização e sem CPF.
  - O código do comprovante é sorteado, não sai do HMAC. O HMAC-SHA256 sela os dados assinados, e o comprovante mostra
    esse rótulo.
  - A imagem sai em JPG, com a fonte DejaVu.
  - Excluir a batida não apaga o comprovante emitido (a ligação com a batida fica nula, `SET NULL`).
  - O NSR vai só para a batida que a própria pessoa registra, inclusive a da fila sem internet (E14). A lançada pelo
    gestor e a criada pelo abono ficam sem NSR. O mesmo envio repetido (E13) não consome número.
  - Dois limites de tentativas: `ponto_validacao` na página pública e `ponto_comprovante_imagem` no download da imagem.

**E16. Folha com código BJF, PDF novo e aceite mensal.**
- Entra: P22 ("Assinar folha"), P33 (o texto do aceite vai ao pacote jurídico, sem travar), P34 e D17 (PDF novo em
  imagem, gerado no servidor, com o arranjo do desenho, código BJF, QR e "Base Legal"), P35 (a: o PDF mostra as
  pendências do dia; "registro em outro escritório" continua fora), P36 (as três frases do desenho ficam; as duas
  primeiras anotadas no pacote jurídico), P87, P88 e P89 (ficam: XLSX, ver antes de baixar, o gestor exporta a folha de
  outro), D6 (dados das mesmas fontes do PDF de hoje, nenhum campo novo), D19 ("alterada depois do aceite" e nova
  assinatura). A chave `ponto-aceite` entra no catálogo aqui, ligada (P96).
- Depende de: E7, E9, E15; de preferência depois do E10. Pendência do dono: o mesmo segredo do HMAC (seção 10).
- Gancho do segredo: a mesma `PONTO_HMAC_SECRET` da E15. Com ela vazia a etapa entra pronta e não trava: o PDF novo sai
  sem código BJF e sem QR, nenhuma folha emitida é gravada e "Assinar folha" não aparece (o aceite se liga à folha
  emitida com código).
- Migration: sim (folha emitida e aceite).
- Arquivos: `folha_pdf.html.twig`, exportação do PDF no `PontoController.php`, `FolhaPontoXlsxExporter.php` se o código
  for também no XLSX. Frente: é a pendência 2 dela ("Jornada alterada em dd/mm" no cabeçalho). Combinar quem entra primeiro.
  A forma de gerar a imagem no servidor se escolhe na E16, de preferência com o que já gera o PDF de hoje.
- Testes: código da folha validado pela página pública; aceite só de mês fechado; ressalva sem texto recusada; PDF com
  as pendências do dia e sem "registro em outro escritório"; as três frases no PDF; com `PONTO_HMAC_SECRET` vazia o PDF
  sai sem código e "Assinar folha" some; `FolhaExportacaoMesmaEscolhaTest` verde.

**E17. Banco de horas por lotes.**
- Obrigatória (P51 a).
- Entra: P51 (a: aba "Banco de Horas" com os lotes do desenho, sem zerar em janeiro; as horas pagas seguem no saldo),
  P52, P53 (fica), P73, P2 completo.
- Pendência "Saldo mês do dia em andamento" (vinda da E2; decisão do Coordenador de 09/11/2026, regra 1: o desenho
  manda). No dia em andamento, a coluna "Saldo mês" da folha mostra o acumulado até ontem (desenho L157). O motor passa a
  entregar esse número num campo novo da linha de hoje (o `saldoAcumulado` de hoje continua nulo até a saída), calculado
  com todos os dias do mês, inclusive os que a /ponto não recebe. Até lá a célula fica vazia. Teste: o número da linha de
  hoje igual ao da linha de ontem na ficha (que mostra todos os dias), e o dia 1º sem número.
- Depende de: E7 (limite e prazo na configuração).
- Saldo de antes do início dos lotes ("Sem data de origem", seção 3; decisão do Coordenador de 09/11/2026): os lotes
  nascem no mês do início da contagem da pessoa e atravessam os anos, e o lançamento de horas pagas de uma competência
  anterior vira o lote da própria competência. Sem fonte de saldo anterior ao primeiro lote, a linha "Anterior a ..." do
  desenho não aparece. Se um dia houver essa fonte, ela entra como um lote inicial com a data do primeiro dia do banco.
- Jornada (decisão do Coordenador de 09/11/2026): o banco conta pela mesma jornada da folha, a pessoal ou, sem ela, a
  do escritório (spec da jornada, §10.4). Vale em toda tela, no PDF, no XLSX e na ficha do gestor. Antes, quem não tinha
  jornada pessoal não tinha as faltas contadas no banco. Medição em produção em 09/11/2026: muda 1 pessoa, em 1
  escritório, com o novo saldo em -24h.
- Rodapé (decisão do Coordenador de 09/11/2026): o "Saldo do mês" da /ponto e o da ficha do gestor vêm do banco da
  competência, sem as horas pagas (que somam à parte). A /ponto esconde os dias sem batida e eles contam, então a
  última linha visível não serve. Em toda competência que a tela deixa escolher, o total bate entre a /ponto, a
  ficha, o PDF e o XLSX, inclusive no mês só com lançamento de horas pagas.
- Textos da aba (decididos na revisão):
  - Selos no singular quando cabe: "Vence hoje", "Vence em 1 dia", "1 mês".
  - Os dias contam corridos.
  - Sem lotes, a tabela mostra "Nenhum lote no banco de horas.".
  - O compensado zerado mostra "0h", e o lote sem vencimento mostra "Sem prazo".
- Migration: não; os lotes são calculados a partir da folha. Tabela `ponto_banco_lote` só se o cálculo se mostrar
  necessário.
- Arquivos: `FolhaPontoBuilder.php` (`calcularSaldoAnual`; frente: avisar), serviço novo de lotes.
- Testes: crédito mais antigo abatido primeiro; vencimento no último dia do mês "competência mais prazo"; horas pagas
  continuam no saldo; o saldo não zera em 1º de janeiro; o saldo de cada pessoa antes e depois da troca fica registrado
  no PR (muda o número de todo mundo).

**E18. Reconhecimento facial.**
- Entra: P76, P77, P78, P15 (variante facial), D10 (template no fornecedor; foto só com a opção do escritório e
  retenção), D4 (confirmação antes de excluir o cadastro facial).
- Depende de: E7, E9, E15; jurídico pelo pacote, sem travar. Pendência do dono: contratar o fornecedor da biometria com
  prova de vida (P76, P78, D10, seção 10).
- Gancho do fornecedor: a etapa entra com uma interface de fornecedor (cadastrar o rosto, conferir com prova de vida,
  excluir o cadastro) e um dublê de teste que a implementa. A chave `ponto-facial` nasce desligada e fica assim até o
  contrato (exceção ao P96: a etapa só fica pronta com o fornecedor real); como hoje chave sem linha vale ligada, o
  catálogo precisa de padrão desligado só para ela. O fornecedor real entra depois, num PR pequeno que só implementa a
  interface.
- Migration: sim (cadastro facial, autorização e recusa; foto opcional com retenção).
- Arquivos: interface do fornecedor e dublê de teste, armazenamento privado da foto, purga pelo agendador (configuração
  do agendador é arquivo quente: avisar).
- Testes: sem autorização não há facial e a batida segue pelos outros meios; recusa sem penalidade; excluir cadastro apaga
  aqui e no fornecedor; foto some no prazo; facial obrigatória sem câmera explica e não trava sem saída.

## 6. (d) Migrations necessárias

Número de cada uma: a pedir ao Coordenador (já dado: E9, `Version20261109190000`, tabela `ponto_auditoria`). Recomendação geral: reaproveitar as tabelas que existem
(`registro_ponto`, `justificativa_ponto`, `sede`, `feriado`, `jornada_*`), sem renomear: renomear não traz ganho e mexe
em todo o Ponto e na frente jornada-vigencia.

| Tabela do ESPEC | Hoje | Situação | O que fazer | Etapa |
|---|---|---|---|---|
| `ponto_batida` | `registro_ponto` | Já existe com outro nome | Reaproveitar. Colunas novas: marca de trabalho externo e motivo (E12); hora do aparelho, hora de chegada, marca "enviada sem internet", identificador único do envio (E13); método (sistema ou facial) (E18) | E12, E13, E18 |
| `ponto_ajuste` | Não existe (o gestor faz UPDATE e DELETE) | Precisa criar (P65 a) | Tabela nova: escritório, pessoa, batida (vazia na inclusão), data, tipo, valor anterior, valor novo, autor, criado em, motivo, justificativa ligada | E10 |
| `ponto_justificativa` | `justificativa_ponto` | Já existe com outro nome | Reaproveitar. Colunas novas: `motivo` (texto nulo, feita na E4, `Version20261109210000`); batida ligada, opcional (trabalho externo, E12) | E4, E12 |
| `ponto_comprovante` | Não existe | Precisa criar | Tabela nova, uma linha por batida: NSR, código, HMAC (com `PONTO_HMAC_SECRET`; vazia, não grava linha), emitido em, dados assinados; mais um contador de NSR por escritório (linha travada na gravação), que sobe mesmo sem comprovante (D2) | E15 |
| `ponto_folha_aceite` | Não existe | Precisa criar | Escritório, pessoa, ano, mês, situação (concordo ou ressalva), texto da ressalva, aceito em, IP, navegador, folha emitida vista; único por pessoa, escritório e mês | E16 |
| (folha emitida, fora da lista do ESPEC) | Não existe | Precisa criar | Código BJF, HMAC, escritório, pessoa, competência, emitida em, emitida por, resumo do conteúdo | E16 |
| `ponto_sede` | `sede` (legado, `src/Entity/Tenant/Sede.php`: latitude, longitude, raio, fuso) | Já existe com outro nome | Reaproveitar. P70 (a): sede nova nasce com 150 m (padrão na configuração do E7); as sedes de hoje não mudam, sem migration de dado | nenhuma |
| `ponto_feriado` | `feriado` (por escritório, recorrente ou pontual) | Já existe com outro nome | Reaproveitar. Coluna nova: sede opcional (abrangência, P71) | E6 |
| `ponto_escala` | `jornada_colaborador` e `bloco_jornada_colaborador` (com vigência), `jornada_tenant` e `bloco_jornada` | Decidido (P56 a) | Não criar tabela. As 4 escalas do desenho viram modelos que preenchem os blocos; trocar a escala na aba Equipe grava uma versão nova da jornada, valendo a partir de hoje (`SalvarVersaoDaJornadaUseCase`) | nenhuma (o uso é na E5) |
| `ponto_banco_lote` | Não existe; há `ponto_lancamento_horas_pagas` | Decidido (P51 a) | Lotes do desenho, sem zerar em janeiro; horas pagas seguem no saldo. Recomendação mantida: calcular os lotes a partir da folha, sem tabela; tabela só se o cálculo se mostrar necessário | E17 |
| `ponto_config_escritorio` | Parcial: `jornada_tenant` (validações e alertas), `home_office_config` (por pessoa) | Precisa criar | Tabela nova, uma por escritório: 8 chaves do escritório, modo facial, guardar foto, meses de retenção, limite e prazo do banco, permitir trabalho externo, raio padrão (150 m, P70). Não engordar `jornada_tenant` (PR 3 da frente) | E7 |
| `ponto_auditoria` | `audit_log` genérico, sem encadeamento | Criada na E9 (`Version20261109190000`) | Escritório, em, autor, ação, detalhe, selo anterior, selo; índice por escritório e data. Só os eventos que o desenho grava (E9); mudanças de configuração (sede, feriado, limite, prazo, modo facial, foto) ficam no `audit_log` (D5 não) | E9 |
| `recurso_estado` (chaves `ponto-*`) | Existe | Sem migration | Linhas novas só no catálogo do código | E7 |
| (cadastro facial, fora da lista do ESPEC) | Não existe | Precisa criar | Pessoa, escritório, autorizado em, recusado em, versão do texto, IP, navegador, referência do template no fornecedor, excluído em; fotos no armazenamento privado com prazo | E18 |

## 7. Liga e desliga do Master

Hoje não existe liga e desliga do Ponto no Master: o catálogo de "Telas e recursos" (`CatalogoDeRecursos::LINHAS`) não
tem `ponto`. O Ponto hoje se liga pela permissão `modules.ponto.view` no perfil, pela chave por pessoa "Registra ponto
eletrônico", por "Habilitar alertas de ponto" na jornada e pelas validações da Jornada do escritório.

No desenho (`bj-recursos.js`), todas nascem ligadas e todas podem ser desligadas. Cada função também tem a chave
"Escritório" (padrão ligada); a função vale quando Global e Escritório estão ligados, e o Global desligado trava a do
escritório.

| id | Rótulo no Master | O que faz | Desligada | Relação com o que existe | Padrão recomendado |
|---|---|---|---|---|---|
| `ponto` (tela) | Ponto Eletrônico | A tela inteira | Quem não é Master vê "Ponto Eletrônico em preparação"; o Master vê a faixa vermelha | A tela está em uso pelos clientes | Ligada |
| `ponto-animacao` | Ponto: validação em tela cheia | "Relógio completando a volta e confirmação ocupando toda a tela." | Resultado vira aviso flutuante (a batida facial sempre usa a tela cheia) | Novo | Ligada depois do E8 |
| `ponto-sons` | Ponto: sons | "Plim no registro e alerta diferente quando o ponto falha." | Some o botão de som; sem plim, sem som de erro, sem som do lembrete | Hoje só há som na janela de alerta de horário | Ligada |
| `ponto-comprovante` | Ponto: comprovante em imagem | "Imagem em alta definição com código de validação, QR Code, marca d'água e padrões de segurança." | Some a aba Comprovantes, o código e "Baixar comprovante" | Novo | Desligada até o E15 e o jurídico |
| `ponto-geo` | Ponto: geolocalização | "Exige que o registro seja feito dentro do raio de uma sede." | Sem caixa de localização e sem checagem de raio | Hoje a cerca vale sempre; desligar num escritório é mudança de regra (ligada ao P18) | Ligada |
| `ponto-facial` | Ponto: reconhecimento facial | "Confirma a identidade pela câmera, com cadastro e autorização prévios." | Some o botão facial e o modo obrigatório | Novo | Desligada até o E18 |
| `ponto-lembrete` | Ponto: lembrete de saída | "Avisa quando passar do horário previsto e a saída não foi registrada." | Some "Saída prevista"; sem aviso, som e notificação do lembrete | Diferente da janela de alerta de horário de hoje (P84), que continua com a sua própria chave na jornada | Ligada |
| `ponto-alertas` | Ponto: alertas de intervalo | "Avisa antes da batida sobre intervalo, interjornada e descanso semanal." | Sem a janela "Antes de registrar" e sem o aviso de intervalo | Hoje as validações da Jornada do escritório recusam (P21) | Ligada, depois do E11 |
| `ponto-aceite` | Ponto: aceite da folha | "O colaborador confirma ou registra ressalva na folha fechada." | Some "Assinar folha" (o selo já gravado continua) | Novo | Desligada até o E16 |

Cuidados: a chave da tela não pode derrubar as consultas globais do Ponto (atalho do avatar, alerta de horário, cartão
diário) nem a página pública de validação; a chave "Escritório" segue a permissão `admin.ponto.manage` de hoje, além do
primário e do Master (P58).

Decisão do P96 (09/11/2026): vale o desenho. Cada chave nasce ligada quando a sua etapa ficar pronta (seção 5); a coluna
"Padrão recomendado" acima fica só como registro da fase 1. A exceção é `ponto-facial`, desligada até o contrato do
fornecedor (E18).

## 8. Textos do desenho com travessão e como ficam

São 15 ocorrências (11 de "travessão longo" e 4 de "travessão médio"), todas como marcador de "sem valor".

| # | Linha | Onde | Como fica |
|---|---|---|---|
| 1 | L154 | Folha na tela: batida que falta hoje ou em feriado | Célula vazia |
| 2 | L411 (2x) | Equipe de exemplo, "Diego Rocha": Entrada e Último registro | Não entra (dado fictício); no sistema, "Sem registro" |
| 3 | L681 | PDF: batida que falta | Célula vazia |
| 4 | L682 (3x) | PDF: Horas Trabalhadas, Horas Extras e Saldo do Mês em dia futuro | Célula vazia |
| 5 | L755 | Minhas Justificativas: "Obs. da Análise" da justificativa em análise | "Aguardando análise" |
| 6 | L755 | Minhas Justificativas: "Analisado em" sem análise | Célula vazia |
| 7 | L776 (4x) | Banco de Horas: Crédito, Compensado, Saldo e Vence em sem valor | "0h", "0h", "0h" e "Sem prazo" |
| 8 | L779 (2x) | Equipe, linha "Você": Entrada e Último registro sem batida | "Sem registro" |

## 9. Estado (para retomar)

- 09/11/2026: fase 1 entregue (esta spec) e decidida no mesmo dia (`decisoes-desenhos-0911.md`, na pasta
  bluejus-coordenacao): seção 2 (P1 a P96) e seção 4 (D1 a D22) com a decisão em cada item; seção 5 ajustada.
- Estado: fase 1 decidida; E1 implementada na branch `ponto-desenho-0811` (commit 190ccb660 e as correções da
  revisão de 09/11/2026), sem migration. Decisões da etapa registradas na E1 (seção 5).
- E2 implementada na branch `ponto-desenho-e2` (empilhada na E1), sem migration. Decisões da etapa registradas na E2
  (seção 5).
- E3 implementada e integrada na branch de integração `ponto-desenho` (sobre a E2), sem migration, em parciais novos (a tela antiga fica
  intocada). Decisões e os pontos de liga e desliga do P40, P43 a e P41 registrados na E3 (seção 5).
- E13 integrada na branch `ponto-desenho` (frentes `ponto-desenho-e13`, servidor, e `ponto-desenho-e13-tela`), com a
  migration `Version20261109220000`. Decisões da etapa registradas na E13 (seção 5).
- E14 integrada na branch `ponto-desenho` (as mesmas frentes da E13), sem migration. Decisões e limites registrados na
  E14 (seção 5).
- E4 implementada na branch `ponto-desenho-e4` (empilhada na E3), com a migration `Version20261109210000` (coluna
  `motivo` em `justificativa_ponto`). Decisões registradas na E4 (seção 5).
- E7 implementada na branch `ponto-desenho-e7`, com a migration Version20261109180000 (`ponto_config_escritorio`).
  Decisões registradas na E7 (seção 5).
- Aceitos na E7 (links que ainda levam à /ponto fechada quando o Master desliga a tela `ponto`; quem não é Master vê
  a página "em preparação", sem erro nem dado exposto): o botão "Ir para o Ponto Eletrônico" da janela de alerta de
  horário (`templates/ponto/_modal_alerta_ponto.html.twig`); a volta da análise da própria justificativa pela aba
  "Minhas Justificativas" (`TenantController::voltaDaAnalise`, redirect para `ponto_index`); e os links das
  notificações de justificativa abonada, recusada e do "aprovar todos" (`TenantController`,
  `generateUrl('ponto_index')`). O cartão diário de pendência, esse sim, some com a tela fechada.
- Para a E15: a futura página pública de validação NÃO pode ter nome de rota com o prefixo `ponto_` (o catálogo do
  Master derruba toda rota `ponto_*` quando a tela `ponto` é desligada), ou precisa entrar em
  `CatalogoDeRecursos::ROTAS_SEMPRE_ABERTAS`.
- E9 implementada na branch `ponto-desenho-e9` integrada na `ponto-desenho`, com a migration
  `Version20261109190000` (tabela `ponto_auditoria`). Vai ao ar junto com a E10, num PR único no fim. Decisões
  registradas na E9 (seção 5).
- E5 implementada na branch `ponto-desenho-e5` e integrada na `ponto-desenho`, sem migration. Decisões registradas na
  E5 (seção 5). O "Abonar selecionadas" usa o abono da ficha (`AbonoPelaAnaliseDaJustificativa`).
- Funções do sistema que voltam (regra 2), na branch `ponto-desenho-funcoes`, sem migration: o "+" do esquecimento
  hoje (P30 e E2), o tempo do intervalo ao vivo e a saída prevista durante o intervalo (E1, "Funções do sistema que
  voltam").
- E6 implementada na branch `ponto-desenho-e6`, com a migration `Version20261109200000` (feriado por lotação, desvio
  necessário do P71). Decisões registradas na E6 (seção 5).
- Próximo passo: PR da E1 e smoke do dono na tela; depois o PR da E2. A E7 pode correr junto.
- Pontos que se fecham com o Coordenador ao abrir a etapa: teto de idade da fila (E14), saldo de antes dos lotes (E17).
  A chave do selo da trilha (E9) já foi decidida.
- Pendências do dono: seção 10. Nenhuma trava a E1.
- Migrations: `Version20261109180000` (E7, `ponto_config_escritorio`), `Version20261109190000` (E9, tabela
  `ponto_auditoria`), `Version20261109210000` (E4) e `Version20261109220000` (E13).

## 10. Pendências do dono e ganchos

Nenhuma destas trava o código: cada etapa entra pronta com o gancho abaixo e passa a valer quando o dono resolver.

**Chaves por finalidade (decidido pelo Coordenador em 09/11/2026).** O dono cria em produção um segredo só, o
`PONTO_HMAC_SECRET`. O código nunca usa o segredo direto: deriva uma chave por finalidade com
`hash_hkdf('sha256', $segredo, 32, '<finalidade>')`. Finalidades: `ponto-comprovante` (comprovante da batida, E15),
`ponto-folha` (folha emitida, E16) e `ponto-trilha` (selo da trilha encadeada, E9). Cada registro selado guarda a versão
da chave usada (começa em 1), para que uma troca futura do segredo não quebre a conferência do que já foi selado: a
conferência escolhe a chave pela versão gravada na linha.

**Deploy (a partir da E9).** Antes de publicar, conferir que `PONTO_HMAC_SECRET` existe no `.env.prod` (só o nome:
`cut -d= -f1`). Sem ela nada quebra, mas a trilha da E9 grava sem selo e a aba Auditoria mostra "sem selo"; se ela
faltar por um tempo e voltar, os eventos desse intervalo aparecem como trecho sem selo.

| Pendência (itens) | Por que é do dono | Gancho no código | Etapa que precisa |
|---|---|---|---|
| Conferir o relógio (NTP) da VPS (P5) | Produção; o Claude não entra na VPS | Nenhum gancho novo: a batida e a "Hora oficial" já usam o relógio do servidor. O comando de conferência (por exemplo, `timedatectl status`, linha "System clock synchronized") vai ao Samuel em bloco para o terminal externo | E13 (e a linha "Hora oficial" da E1) |
| Criar em produção o segredo do HMAC do comprovante e da folha (P34, P48, P50) | Credencial | Variável de ambiente `PONTO_HMAC_SECRET`, declarada vazia no `.env` do repositório (só o nome). Vazia: a batida grava com NSR, nenhum comprovante nem folha com código é emitido, a aba Comprovantes, o "Validar código" e o "Assinar folha" não aparecem, e nada quebra. Preenchida: a emissão começa sozinha | E9 (selo da trilha), E15 e E16 |
| Contratar o fornecedor da biometria facial com prova de vida (P76, P78, D10) | Contratação | Interface de fornecedor (cadastrar, conferir com prova de vida, excluir cadastro) com um dublê de teste; chave `ponto-facial` desligada até o contrato. O fornecedor real entra depois, num PR pequeno que só implementa a interface | E18 |
