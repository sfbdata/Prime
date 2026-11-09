# Ponto Eletrônico pelo desenho 16 (VS 08.11.26): fase 1, análise

**Risco:** ALTO (ponto eletrônico) · **Origem:** desenho "16 - Ponto Eletronico - VS 08.11.26", artefato
https://claude.ai/code/artifact/3c9fe618-65e4-457f-8d3d-c31bb68901c8, cópia em `docs/design/redesign-08-11-26/`
(com `ESPEC_PONTO_ELETRONICO.md` e o inventário `inventario-ponto-0811.md`) · **Data:** 09/11/2026 ·
**Estado:** aguardando o aceite do Samuel item a item; nenhum código antes.

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
4. Treze itens batem de frente com decisões do dono já registradas (cerca, editar batida, banco anual, botão cinza, validações...): são (PERGUNTA).
5. Funções do sistema que o desenho não mostra (ficha do colaborador, Home Office, sedes com mapa, XLSX, alertas) ficam, cada uma com o seu item.
6. Facial, comprovante com valor legal, fila sem internet e o que depende de lei ou fornecedor vão por último, atrás das chaves do Master.
7. Nada toca a frente jornada-vigencia sem aviso: cada etapa diz quais arquivos dela encosta.

## 2. (a) Antes e depois, item a item (para o Samuel responder)

Como responder: diga o número e "sim" ou "não" (exemplo: "P1 sim, P7 b, P13 fica"). Só entra o que for aceito.
"Fica" quer dizer: a função existe hoje, o desenho não mostra, e ela continua onde está.
(PERGUNTA) quer dizer: o desenho vai contra uma decisão do dono já registrada; escolha uma das opções.
"(decidido)" quer dizer: já resolvido pelo Coordenador; está aqui só para constar, sem resposta.

### Registrar ponto

- **P1.** Página em duas colunas. Hoje: cartões um embaixo do outro, no estilo antigo do sistema. | No desenho: título grande "Ponto Eletrônico"; à esquerda o cartão "Registrar Ponto"; à direita a "Folha de Ponto" e um cartão com abas; cores, bordas e cantos do desenho; no celular vira uma coluna só. | Resposta: [ ] aceito [ ] não
- **P2.** Banco de horas no topo do cartão. Hoje: saldo do ano no cabeçalho do cartão, verde ou vermelho. | No desenho: barra azul com "Banco de horas" no canto direito e um triângulo amarelo quando o saldo passa do limite (o número depende do P51). | Resposta: [ ] aceito [ ] não
- **P3.** Situação do dia. Hoje: não aparece. | No desenho: frase acima do relógio: "Aguardando entrada", "Em jornada", "Em repouso" ou "Jornada encerrada". | Resposta: [ ] aceito [ ] não
- **P4.** Relógio do dia. Hoje: relógio do tempo trabalhado hoje. | No desenho: o mesmo relógio, grande, com horas, minutos e segundos. | Resposta: [ ] aceito [ ] não
- **P5.** Hora oficial. Hoje: não aparece. | No desenho: linha "Hora oficial 14:32:10 · segunda-feira, 09/11/2026", com a hora do servidor (não a do celular). | Resposta: [ ] aceito [ ] não
- **P6.** Escolha do tipo. Hoje: lista para escolher (Entrada, Repouso, Retorno, Saída), já no próximo tipo. | No desenho: 4 botões lado a lado, o próximo já marcado, dá para escolher outro; cada botão mostra a hora já batida ou "--:--:--". | Resposta: [ ] aceito [ ] não
- **P7. (PERGUNTA)** Tipo já batido e dia completo. Hoje: dá para bater o mesmo tipo de novo (a folha usa a primeira entrada e a última saída) e o botão nunca fica cinza, sempre responde ao toque. | No desenho: o tipo já batido fica verde e não aceita clique; com os 4 feitos o botão vira "Jornada de hoje concluída", cinza e sem clique. | Origem: specs ponto-batida-que-responde-e-conta-certa (o botão responde ao toque) e ponto-folha-uma-batida-por-tipo (§7.1). | Opções: (a) travar como o desenho; (b) mostrar o verde e a hora, mas deixar bater de novo, como hoje. Recomendo (b). | Resposta: [ ] a [ ] b
- **P8.** Batidas de hoje. Hoje: lista "Suas batidas de hoje" abaixo do botão, com o selo "N registros" quando o mesmo tipo foi batido mais de uma vez. | No desenho: a hora fica dentro de cada botão do tipo e a lista sai. Se aceito, o aviso de batida repetida (que o sistema tem) precisa de lugar: recomendo no próprio botão do tipo. | Resposta: [ ] aceito [ ] não
- **P9.** Caixa da localização. Hoje: caixa acima do botão com "GPS: 12m", impreciso, fora da área (sede e distância), "Home Office hoje" e erro. | No desenho: caixa com faixa colorida à esquerda e botão de atualizar: "Obtendo a sua localização...", "Localização confirmada. Precisão de N m.", "GPS impreciso (N m). O sistema ainda validará se você está dentro do raio de alguma sede.". Se aceito, os estados que só o sistema tem (fora da área, Home Office hoje, erro) ganham o mesmo molde. | Resposta: [ ] aceito [ ] não
- **P10. (PERGUNTA)** Erro de localização. Hoje: texto para cada tipo de erro, botões "Permitir localização" e "Tentar de novo" e passo a passo por navegador e celular, sem travar o botão. | No desenho: o erro só aparece depois do toque, na tela vermelha: "Não foi possível obter a sua localização" e "Ative o GPS do aparelho, permita a localização no navegador e tente de novo.". | Origem: spec ponto-gps-erro-que-explica. | Opções: (a) só o texto do desenho; (b) a tela vermelha do desenho e, na caixa da localização, o passo a passo de hoje. Recomendo (b). | Resposta: [ ] a [ ] b
- **P11. (PERGUNTA)** Resposta depois do toque. Hoje: caixa abaixo do botão (registrado, recusado, sem rede, sem confirmação) e o botão "Atualizar e conferir minhas batidas". | No desenho: tela cheia verde ou vermelha (P79) ou aviso flutuante no rodapé (P80); não há caixa abaixo do botão. | Origem: decisão do dono no teste de 14/09, "duas caixas com donos diferentes" (spec ponto-batida-que-responde-e-conta-certa). | Opções: (a) só o desenho; (b) o desenho e, nos casos "sem rede" e "sem confirmação", a caixa de hoje com o botão de conferir. Recomendo (b). | Resposta: [ ] a [ ] b
- **P12.** Aviso do intervalo e da saída. Hoje: contador do repouso com aviso do mínimo e "Previsão de saída: HH:MM". | No desenho: caixa colorida "Intervalo em andamento. O mínimo de 1h se completa em HH:MM.", "Intervalo mínimo de 1h cumprido.", "Saída prevista às HH:MM." e, passada a hora, "Passou da saída prevista (HH:MM). Não esqueça de registrar a saída.". Se aceito, o mínimo do intervalo vem da configuração do escritório (o desenho fixa 1h). | Resposta: [ ] aceito [ ] não
- **P13.** Fica (o desenho não mostra): "X a mais hoje" (horas a mais depois de cumprir o dia). Fica onde está? | Resposta: [ ] sim, fica [ ] não, sai
- **P14.** Última pendência no cartão. Hoje: não aparece no cartão (aparece na lista de pendências e no cartão diário). | No desenho: antes da entrada do dia, faixa amarela "Última pendência: dd/mm/aaaa, falta a saída." com o botão "Regularizar", que abre o registro de esquecimento. | Resposta: [ ] aceito [ ] não
- **P15.** Botão principal. Hoje: "Bater Ponto" com ícone de digital e o tipo no rótulo. | No desenho: botão azul alto "Bater Ponto: Entrada" com a digital desenhada; com a facial obrigatória vira "Bater Ponto com facial: Entrada". | Resposta: [ ] aceito [ ] não
- **P16.** Rodapé do cartão. Hoje: botão "Justificar Falta" e, em outro cartão, "Jornada semanal: 44h00m". | No desenho: uma linha com "Justificar falta", o botão de som e "Jornada semanal 44h00m" (o número vem da jornada da pessoa, ver seção 4). | Resposta: [ ] aceito [ ] não
- **P17.** Fica (o desenho não mostra): selo "Personalizada" ou "Padrão do escritório" junto da jornada semanal. Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P18. (PERGUNTA)** Fora do raio da sede. Hoje: a batida é recusada ("Você está fora da área permitida das sedes"), na tela e no servidor. | No desenho e na regra nova do dono (ESPEC): tela vermelha "Você está fora do raio permitido" com o botão "Registrar como trabalho externo", que pede "Local e motivo", registra a batida e manda para a análise do gestor. | Origem: decisão do dono de 11/09/2026, "a cerca continua bloqueando" (spec ponto-batida-nao-se-perde-no-navegador). | Opções: (a) seguir o desenho; (b) manter o bloqueio. Recomendo (a): a regra nova é do próprio dono e é mais recente. | Resposta: [ ] a [ ] b
- **P19.** Fica (o desenho não mostra): Home Office (no dia liberado, bate sem localização e a folha mostra o selo). Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P20. (PERGUNTA)** Batida sem internet. Hoje: não há fila; a hora é a de chegada no servidor e o envio com mais de 60 segundos de atraso não sai (a pessoa bate de novo). | No desenho: a batida fica guardada no aparelho, aparece "Sem internet: N batida(s) aguardando envio." e ela vai sozinha quando a internet volta. | Origem: spec ponto-batida-nao-se-perde-no-navegador (usar a hora do toque ficou como "decisão do dono para frente futura"). | Opções: (a) aceitar a fila: vale a hora do aparelho, as duas horas ficam guardadas e a batida aparece marcada "enviada sem internet" para o gestor; (b) sem fila, como hoje. Recomendo (a), numa das últimas etapas. | Resposta: [ ] a [ ] b
- **P21. (PERGUNTA)** Intervalo curto e descanso entre jornadas. Hoje: quando o escritório liga as validações, o retorno com repouso menor que o mínimo e a entrada antes do descanso entre jornadas são recusados. | No desenho e na regra nova do dono: janela "Antes de registrar" com o aviso e o botão "Registrar assim mesmo" ("O alerta não impede o registro: a folha deve refletir o horário real."); a folha marca "não conforme"; também avisa o 7º dia seguido sem descanso. | Origem: specs ponto-esquecimento-nao-trava (R4: "quando a saída foi batida normalmente, a interjornada continua como hoje") e ponto-por-escritorio. | Opções: (a) avisar sem recusar; (b) manter a recusa. Recomendo (a). | Resposta: [ ] a [ ] b

### Folha

- **P22.** Barra da folha. Hoje: cartão "Folha de Ponto - MM/AAAA" com filtro do mês e o botão "Visualizar / Exportar". | No desenho: barra azul com o mês para escolher, "Baixar PDF" e, quando ligado, "Assinar folha" e o selo "Assinada em dd/mm/aaaa". | Resposta: [ ] aceito [ ] não
- **P23.** Meses que o colaborador vê. Hoje: só o mês atual e o anterior. | No desenho: todos os meses desde o início do uso. | Resposta: [ ] aceito [ ] não
- **P24.** Cores das linhas. Hoje: fim de semana em cinza e feriado em amarelo. | No desenho: linhas listradas e, ao passar o mouse, a linha fica azul com letra branca. | Resposta: [ ] aceito [ ] não
- **P25.** Quais dias aparecem. Hoje: todos os dias do mês. | No desenho: só os dias com batida (e feriado com horas). Atenção: no desenho o dia sem batida some da tela, mas desconta do saldo. Recomendo "não" (continuar com todos os dias). | Resposta: [ ] aceito [ ] não
- **P26.** Folha no celular. Hoje: a tabela rola para o lado. | No desenho: até 760 px de largura, cada dia vira um cartão com o nome de cada campo. | Resposta: [ ] aceito [ ] não
- **P27.** Selos do dia. Hoje: "A conferir", "Registro incompleto", "Home Office". | No desenho: "Fora de sequência" (vermelho, quando um horário é menor que o anterior), "Feriado", "Em andamento" (hoje) e "Registro incompleto". | Resposta: [ ] aceito [ ] não
- **P28.** Fica (o desenho não mostra): selos "A conferir" (dia com batidas fora da conta) e "Home Office". Ficam? | Resposta: [ ] sim, fica [ ] não, sai
- **P29.** Marca "aj" na batida corrigida. Hoje: não há (a batida corrigida pelo gestor é trocada). | No desenho: "aj" ao lado da hora, com a dica "Ajustado por NOME em dd/mm/aaaa. Registro original preservado." (depende do P65). | Resposta: [ ] aceito [ ] não
- **P30. (PERGUNTA)** "+" do esquecimento. Hoje: "+" na batida que falta, só em dia útil passado que tem alguma batida; ampulheta quando já pediu. | No desenho: "+" em qualquer dia passado da folha; hoje e feriado mostram um traço. | Origem: decisão do dono de 28/07 (spec ponto-atalho-esquecimento-registro). | Opções: (a) desenho; (b) regra de hoje com o "+" no visual do desenho e a ampulheta. Recomendo (b). | Resposta: [ ] a [ ] b
- **P31.** Coluna Justificativa. Hoje: Abonada, Pendente, Rejeitada, Não abonada, com o tipo e o horário do abono parcial. | No desenho: selos coloridos "Abonada" (verde), "Em análise" (azul) e "Recusada" (vermelho). Se aceito, "Não abonada" e o horário do abono parcial ficam. | Resposta: [ ] aceito [ ] não
- **P32.** Fica (o desenho não mostra): rodapé "Saldo do mês / Horas pagas / Total do mês". Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P33.** Assinar a folha. Hoje: não existe (o PDF tem linhas para assinar no papel). | No desenho: no mês já fechado, botão "Assinar folha" com "Concordo: os registros representam fielmente minha jornada" ou "Discordo de parte dos registros (ressalva)" e o texto da ressalva; depois, selo "Assinada em" ou "Ressalva em" e carimbo no PDF. Não dá para desfazer. | Resposta: [ ] aceito [ ] não
- **P34.** PDF novo da folha. Hoje: PDF em texto, com empregador, empregado, totais e linhas de assinatura em papel. | No desenho: PDF de uma página em imagem (texto não selecionável) com o código BJF, QR Code, carimbo do aceite, quadro de intervalos e "Base Legal". | Resposta: [ ] aceito [ ] não
- **P35. (PERGUNTA)** PDF com pendência. Hoje: PDF e XLSX nunca mostram "a conferir", "incompleto" nem "registro em outro escritório". | No desenho: o PDF escreve "Registro incompleto", "Horário fora de sequência: revisar" e "Falta: em análise" nas observações do dia. | Origem: specs ponto-abono-nao-perdoa-jornada e ponto-esquecimento-nao-trava (R8). | Opções: (a) desenho; (b) PDF sem pendência, como hoje. Recomendo (b). | Resposta: [ ] a [ ] b
- **P36.** Frases fixas no PDF. Hoje: não há. | No desenho: "OBSERVAÇÕES GERAIS" com "As horas extras foram autorizadas previamente pela gestão e serão compensadas conforme acordo de banco de horas.", "Feriados trabalhados serão compensados." e "Registros realizados pelo sistema eletrônico de ponto BlueJus.". Recomendo "não" para as duas primeiras (afirmam algo que o sistema não sabe). | Resposta: [ ] aceito [ ] não

### Pendências

- **P37.** Pendências viram aba. Hoje: cartão "Pendências de registro" (últimos 30 dias, todos os escritórios). | No desenho: aba "Pendências", a primeira, aberta ao entrar, com o número no título, o texto "Dias dos últimos 30 em que faltou uma batida. Elas não impedem o registro do ponto: envie o registro de esquecimento para regularizar." e o botão "Enviar registro de esquecimento". | Resposta: [ ] aceito [ ] não
- **P38.** Fica (o desenho não mostra): link "Trocar de escritório" na pendência de outro escritório. Fica? | Resposta: [ ] sim, fica [ ] não, sai

### Justificativas

- **P39.** "Minhas Justificativas" vira aba. Hoje: cartão com Data(s), Tipo, Status, Atestado, Obs. da Análise, Analisado em. | No desenho: aba com as mesmas colunas, selos coloridos, cadeado "Análise concluída: não pode ser alterada." e só as justificativas do mês escolhido na Folha. | Resposta: [ ] aceito [ ] não
- **P40.** Nomes das situações. Hoje: Pendente, Abonado, Rejeitado. | No desenho: Em análise, Abonado, Recusado. Se aceito, muda em todas as telas, inclusive na ficha do gestor e nos avisos do sininho. | Resposta: [ ] aceito [ ] não
- **P41.** Excluir a justificativa em análise. Hoje: o colaborador só edita. | No desenho: lixeira "Excluir enquanto não analisada" (no desenho apaga sem perguntar; ver seção 4). | Resposta: [ ] aceito [ ] não
- **P42.** Fica (o desenho não mostra): editar a justificativa em análise (tipo, horário, troca do atestado do lote). Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P43. (PERGUNTA)** Gestor abonar a própria justificativa. Hoje: o gestor analisa na ficha de cada colaborador; analisar a própria está em aberto para o dono. | No desenho: na aba "Minhas Justificativas", o usuário primário e o Master veem "Abonar" e "Recusar" nas próprias justificativas. | Origem: spec ponto-edicao-justificativa-analisada (§6, em aberto). | Opções: (a) desenho; (b) ninguém analisa a própria; os botões aparecem só na aba Equipe, para as dos outros. Recomendo (b). | Resposta: [ ] a [ ] b
- **P44.** Janela "Justificar falta". Hoje: janela com calendário de vários dias, tipo, abono parcial, esquecimento e anexo. | No desenho: janela nova (barra azul, botões "Cancelar" e "Enviar") com data única, motivo e atestado opcional. Se aceito, entra a moldura do desenho e os campos de hoje ficam (P45). | Resposta: [ ] aceito [ ] não
- **P45.** Fica (o desenho não mostra): vários dias de uma vez, os 45 tipos em 6 grupos, abono parcial com horário e anexo PDF, JPG ou PNG até 10 MB. Ficam? | Resposta: [ ] sim, fica [ ] não, sai
- **P46.** Janela "Registro de esquecimento". Hoje: janela com o tipo e o horário. | No desenho: "dd/mm/aaaa · Saída", horário com segundos, motivo obrigatório e o aviso "Enviado para aprovação do gestor.". | Resposta: [ ] aceito [ ] não
- **P47.** Fica (o desenho não mostra): janela "Editar justificativa" (ganha a moldura do desenho). Fica? | Resposta: [ ] sim, fica [ ] não, sai

### Comprovantes

- **P48.** Aba Comprovantes. Hoje: não existe. | No desenho: lista de cada batida com data e hora, tipo, método ("Facial" ou "Sistema"), código "BJP-XXXX-XXXX-XXXX" e o botão "Baixar". | Resposta: [ ] aceito [ ] não
- **P49.** Comprovante em imagem. Hoje: não existe. | No desenho: imagem grande com trabalhador, empregador e CNPJ, local, método, número sequencial (NSR), código, QR Code, marca d'água e padrões de segurança. | Resposta: [ ] aceito [ ] não
- **P50.** Validar comprovante. Hoje: não existe. | No desenho: botão "Validar código", janela que responde "Comprovante autêntico." ou "Código não localizado.", e uma página aberta (sem login) para quem ler o QR Code. | Resposta: [ ] aceito [ ] não

### Banco de horas

- **P51. (PERGUNTA)** Banco por lotes. Hoje: saldo do ano, que zera em 1º de janeiro. | No desenho: aba "Banco de Horas" com "SALDO ATUAL", "LIMITE CONFIGURADO" (120h), "PRAZO DE COMPENSAÇÃO" (6 meses) e uma tabela de lotes por mês com vencimento e situação ("No prazo", "Vence em N dias", "Vencido", "Compensado"); as horas negativas abatem primeiro os créditos mais antigos. | Origem: decisão do dono de 31/07/2026 (spec ponto-horas-pagas-banco-de-horas). | Opções: (a) lotes do desenho, sem zerar em janeiro; (b) a aba mostra o saldo do ano de hoje, sem lotes. Recomendo levar ao dono antes (muda o saldo de todo mundo). | Resposta: [ ] a [ ] b
- **P52.** Aviso acima do limite. Hoje: não há limite. | No desenho: faixa amarela "Saldo acima do limite configurado. Planeje a compensação para evitar passivo." e o triângulo no cartão. | Resposta: [ ] aceito [ ] não
- **P53.** Fica (o desenho não mostra): horas pagas lançadas pelo gestor entram no saldo. Fica? | Resposta: [ ] sim, fica [ ] não, sai

### Equipe

- **P54.** Aba Equipe. Hoje: não há visão da equipe; o gestor abre a ficha de cada um. | No desenho: tabela com Colaborador, Situação ("Em jornada", "Em intervalo", "Sem registro", "Encerrada"), Entrada, Último registro, Banco, Pendências e Escala; a primeira linha é a própria pessoa ("Você"). | Resposta: [ ] aceito [ ] não
- **P55.** Números da equipe. Hoje: não há. | No desenho: cinco quadros: "Em jornada", "Em intervalo", "Sem registro", "Pendências" e "A analisar". | Resposta: [ ] aceito [ ] não
- **P56. (PERGUNTA)** Escala na aba Equipe. Hoje: jornada por dias e horários, com "Vale a partir de" e histórico (entregue em 07/11). | No desenho: lista com 4 escalas fixas (44h de segunda a sábado, 44h de segunda a sexta, 40h, 30h) que troca a escala da pessoa ali mesmo. | Origem: spec ponto-jornada-vigencia (§8 e §10). | Opções: (a) desenho; (b) a coluna só mostra a jornada atual, com link para a ficha. Recomendo (b). | Resposta: [ ] a [ ] b
- **P57.** Abonar várias de uma vez. Hoje: "Aprovar todos" só dentro de um lote, na ficha de cada pessoa. | No desenho: "Justificativas a analisar (N)" com caixas de marcar e o botão "Abonar selecionadas" (não há recusar em lote). | Resposta: [ ] aceito [ ] não
- **P58.** Quem vê Equipe, Auditoria e Configurações. Hoje: quem tem no perfil a permissão de gerir usuários ou o ponto (não só o primário), mais o primário e o Master. | No desenho: só o usuário primário e o Master. Recomendo "não" (seguir as permissões de hoje). | Resposta: [ ] aceito [ ] não
- **P59.** Fica (o desenho não mostra): ficha do colaborador, aba "Justificativas" (lotes, aprovar com observação, rejeitar, aprovar todos, reverter, lançar justificativa já abonada). Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P60.** Fica (o desenho não mostra): ficha do colaborador, aba "Batidas de Ponto" (folha de qualquer mês, adicionar batida, exportar, "Fora da conta", sinal de registro em outro escritório). Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P61.** Fica (o desenho não mostra): ficha do colaborador, aba "Jornada Personalizada" (chave "Registra ponto eletrônico", "Vale a partir de", histórico, "Limpar escala"). Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P62.** Fica (o desenho não mostra): ficha do colaborador, aba "Home Office". Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P63.** Fica (o desenho não mostra): ficha do colaborador, aba "Horas pagas". Fica? | Resposta: [ ] sim, fica [ ] não, sai

### Auditoria

- **P64.** Aba Auditoria. Hoje: o sistema guarda o histórico, mas não há aba no Ponto. | No desenho: aba com "Cadeia de eventos íntegra: cada evento é selado com o anterior. Registros originais nunca são sobrescritos." e a lista Data e hora, Autor, Ação, Detalhe e Selo. | Resposta: [ ] aceito [ ] não
- **P65. (PERGUNTA)** Corrigir batida só com ajuste. Hoje: o gestor edita (data, hora, tipo) e exclui a batida na ficha; o esquecimento aprovado cria uma batida nova. | No desenho e na regra nova do dono (ESPEC): a batida nunca é apagada nem trocada; a correção é um registro de ajuste com o valor antigo, o novo, quem fez, quando e por quê; a folha mostra "aj". | Origem: decisão do dono de 11/09/2026, "manter exclusão e alteração de batida pelo administrador" (spec ponto-batida-nao-se-perde-no-navegador). | Opções: (a) só ajuste; (b) editar e excluir como hoje. Recomendo (a): é o que sustenta a frase "Registros originais nunca são sobrescritos". | Resposta: [ ] a [ ] b
- **P66.** Fica (o desenho não mostra): a tela geral de auditoria do sistema, com "desfazer". Fica? (Se o P65 for "a", o desfazer não pode mais trocar nem apagar batida.) | Resposta: [ ] sim, fica [ ] não, sai

### Configurações

- **P67.** Aba Configurações. Hoje: não existe; a configuração do ponto está espalhada (Jornada do escritório, Sedes, Feriados). | No desenho: aba com as 8 funções, cada uma com a chave "Escritório" (e "Global" para o Master), o texto de ajuda e o aviso "Desativado pelo Master para todos os escritórios.". | Resposta: [ ] aceito [ ] não
- **P68.** Sedes dentro da aba. Hoje: página própria "Sedes do ponto". | No desenho: tabela "Sedes e raio de registro" com nome, latitude, longitude, raio em metros, "Usar minha posição", remover e "Adicionar sede". | Resposta: [ ] aceito [ ] não
- **P69.** Fica (o desenho não mostra): mapa das sedes e busca por CEP ou endereço. Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P70. (PERGUNTA)** Raio da sede nova. Hoje: 100 m. | No desenho: 150 m. | Origem: decisão do dono de 11/09/2026 ("o raio de 100 m já foi escolhido contando com ~60 m de imprecisão", spec ponto-batida-nao-se-perde-no-navegador). | Opções: (a) 150 m; (b) 100 m. Recomendo (b). | Resposta: [ ] a [ ] b
- **P71.** Feriados dentro da aba. Hoje: página "Feriados e Folgas" com Data, Nome e Recorrência. | No desenho: "Feriados do escritório" com Data, Nome e Abrangência ("Todas as sedes" ou uma sede) e o texto "Os nacionais já estão incluídos. Cadastre aqui os estaduais e municipais, por sede.". A abrangência por sede é nova. | Resposta: [ ] aceito [ ] não
- **P72.** Fica (o desenho não mostra): feriado que se repete todo ano e as folgas. Ficam? | Resposta: [ ] sim, fica [ ] não, sai
- **P73.** Limite e prazo do banco. Hoje: não existem. | No desenho: campos "Limite do banco (horas)" (120) e "Prazo de compensação (meses)" (6, de 1 a 24). Depende do P51. | Resposta: [ ] aceito [ ] não
- **P74.** Permitir trabalho externo. Hoje: não existe. | No desenho: caixa "Permitir trabalho externo, com motivo e análise" (ligada). Depende do P18. | Resposta: [ ] aceito [ ] não
- **P75.** Fica (o desenho não mostra): "Jornada do escritório" (carga, alertas, validação do repouso e do descanso entre jornadas, blocos de horário). Fica? | Resposta: [ ] sim, fica [ ] não, sai

### Facial

- **P76.** Bater ponto com o rosto. Hoje: não existe. | No desenho: botão "Registrar com reconhecimento facial" (ou facial obrigatória), câmera em tela cheia com "Posicione o rosto na área de marcação", "Pisque os olhos", "Mantenha a posição". | Resposta: [ ] aceito [ ] não
- **P77.** Autorização antes do primeiro uso. Hoje: não existe. | No desenho: janela "Cadastro facial" com os textos da LGPD, a caixa "Li e autorizo o uso do reconhecimento facial para o registro do meu ponto." e o botão "Não autorizo" (quem recusa bate pelos outros meios, sem penalidade). | Resposta: [ ] aceito [ ] não
- **P78.** Ajustes da facial. Hoje: não existe. | No desenho: "Opcional: a pessoa escolhe" ou "Obrigatória em toda batida", "Guardar foto de cada batida facial", guardar por 6, 12 ou 24 meses e "Excluir meu cadastro". | Resposta: [ ] aceito [ ] não

### Avisos e sons

- **P79.** Tela cheia de validação. Hoje: não existe. | No desenho: anel que se completa com "Validando Entrada" e as etapas ("Horário oficial confirmado", "Localização validada", "Registro selado"); depois tela verde "Ponto registrado" (fecha sozinha em 10 s) ou vermelha "Ponto não registrado" com "Tentar novamente". | Resposta: [ ] aceito [ ] não
- **P80.** Aviso flutuante. Hoje: não existe neste formato. | No desenho: faixa colorida no rodapé que some em 3 segundos ("Entrada registrada às HH:MM:SS.", "Justificativa abonada." e outros). | Resposta: [ ] aceito [ ] não
- **P81.** Sons e botão de som. Hoje: som só na janela de alerta de horário, e "Ouvir os toques do ponto" no Meu Perfil. | No desenho: "plim" quando registra, som diferente quando falha e um botão de alto-falante no cartão para silenciar. | Resposta: [ ] aceito [ ] não
- **P82.** Vibração. Hoje: não existe. | No desenho: o celular vibra curto no sucesso e três vezes no erro. | Resposta: [ ] aceito [ ] não
- **P83.** Lembrete de saída. Hoje: a janela de alerta de horário cuida disso (P84). | No desenho: uma vez por dia, passada a saída prevista, aviso âmbar "Passou do horário previsto. Não esqueça de registrar a saída." e notificação do navegador "BlueJus Ponto". | Resposta: [ ] aceito [ ] não
- **P84.** Fica (o desenho não mostra): janela de alerta de horário a cada 60 s (entrada não registrada, intervalo, volta, saída, aviso das 6h), título piscando e som por tipo. Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P85.** Fica (o desenho não mostra): cartão diário "Registro de ponto pendente" com "Lembrar em 10 min" e "Dispensar até amanhã". Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P86.** Fica (o desenho não mostra): avisos no sininho (justificativa enviada ao gestor; abonada ou rejeitada ao colaborador). Fica? | Resposta: [ ] sim, fica [ ] não, sai

### Exportações

- **P87.** Baixar o PDF direto. Hoje: botão "Visualizar / Exportar", que mostra o PDF numa janela antes. | No desenho: "Baixar PDF" baixa direto o arquivo "folha_ponto_NOME-MM-AAAA.pdf" e avisa "Folha baixada em PDF. Código BJF-...". | Resposta: [ ] aceito [ ] não
- **P88.** Fica (o desenho não mostra): exportar a folha em XLSX. Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P89.** Fica (o desenho não mostra): ver o PDF antes de baixar e o gestor exportar a folha de outro colaborador. Fica? | Resposta: [ ] sim, fica [ ] não, sai

### Cabeçalho e telas globais

- **P90. (decidido)** Cabeçalho, menu do topo e camadas globais: fica a moldura do sistema. Hoje: o cabeçalho azul do sistema já segue os desenhos (nome, papel, tela cheia, "Indicar o BlueJus"). | No desenho: o mesmo cabeçalho, o menu branco de telas sem o Ponto, e as camadas de dicas, idioma, tema comemorativo, aviso do plano, lembretes da Agenda, Central de Comunicação. | Fica como o sistema tem; a tela faz só o conteúdo e o link do Ponto entra no menu que já existe (o menu lateral já tem "Ponto Eletrônico").
- **P91.** Fica (o desenho não mostra): atalho do ponto no avatar ("Bater ponto", "Em jornada desde", "Em intervalo desde", "Regularizar ponto de ontem"). Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P92.** Fica (o desenho não mostra): no Meu Perfil, a linha "bate ponto / sem ponto" e "Ouvir os toques do ponto". Fica? | Resposta: [ ] sim, fica [ ] não, sai
- **P93. (decidido)** Tela desligada: fica a moldura do sistema. Hoje: o sistema já tem, para as outras telas, a página "em preparação" e a faixa vermelha "Esta tela está desativada para os usuários. Só o Master está vendo.". | No desenho: as mesmas, para o Ponto. | O Ponto passa a usá-las quando a chave da tela existir (P94).

### Liga e desliga do Master

- **P94.** Chave da tela Ponto. Hoje: o Master não tem chave do Ponto em "Telas e recursos". | No desenho: a tela "Ponto Eletrônico" ganha chave. | Resposta: [ ] aceito [ ] não
- **P95.** Chaves das funções. Hoje: não existem. | No desenho: 8 chaves (tela cheia, sons, comprovante, localização, facial, lembrete de saída, alertas de intervalo, aceite da folha), cada uma com "Global" (Master, vale para todos) e "Escritório" (o escritório decide, dentro do que o Master liberou). Lista na seção 7. | Resposta: [ ] aceito [ ] não
- **P96.** Valor inicial das chaves. No desenho: todas ligadas. Recomendo: tela, localização, sons, lembrete e alertas ligados; tela cheia ligada quando pronta; comprovante, aceite e facial desligados até as suas etapas e o jurídico. | Resposta: [ ] aceito [ ] não

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

| # | O que o desenho faz | Recomendação |
|---|---|---|
| D1 | "Jornada semanal 44h00m" fixa no cartão (L137); não segue a escala. | Mostrar a carga da jornada que vale hoje para a pessoa (leitura da jornada com vigência, sem mexer nela). |
| D2 | NSR = quantidade de comprovantes + 1; com o comprovante desligado o NSR não sobe (L522). | NSR sempre, no servidor, sequencial por escritório, mesmo com `ponto-comprovante` desligado. |
| D3 | Falta abonada continua descontando a carga do dia (L620); o abono só vira ajuste no esquecimento. | Seguir o sistema: abonada zera o negativo do dia; abono técnico não perdoa jornada (spec ponto-abono-nao-perdoa-jornada). |
| D4 | Exclusões sem confirmação: justificativa em análise, sede, feriado, cadastro facial. | Pedir confirmação, na moldura de janela do desenho (convenção do sistema; sedes e feriados já confirmam hoje). |
| D5 | Mudanças de sede, feriado, limite, prazo, modo facial e foto não vão para a trilha. | Entram na trilha do Ponto (hoje já vão ao `audit_log`). |
| D6 | Dados do empregado e do empregador fixos para o Farlei (razão social diferente do nome exibido, CPF e CTPS mascarados, código "0001", lotação, admissão, responsável "FARLEI ASSIS DA ROCHA OAB/DF 49.613", "Sim (acordo individual)"). | Tirar das mesmas fontes do PDF de hoje; nada fixo. O que o sistema não tem (razão social separada do nome, quem assina pelo empregador com OAB, tipo de acordo do banco) vira campo novo só se o Samuel aceitar. |
| D7 | Competência padrão fixa em 11/2026 e lista desde 10/2026 (L430, L773). | Mês corrente pelo relógio do servidor; lista desde o início da contagem da pessoa (respeitando o P23). |
| D8 | Pendências de uma lista fixa (L407). | Cálculo de hoje (`ListarPendenciasDeRegistroUseCase`, 30 dias, todos os escritórios). |
| D9 | Hora oficial, código e comprovante calculados no navegador. | Servidor (ESPEC). |
| D10 | Foto da facial capturada e não gravada. | Template no fornecedor; foto só com a opção do escritório e retenção (seção 3). |
| D11 | Sede sem coordenada não bloqueia; GPS negado deixa bater (modo de demonstração). | Seguir o servidor de hoje: fora do home office, sem localização não registra; sede sem coordenada é ignorada. |
| D12 | Links do cabeçalho apontam para versões antigas de outras telas. | Menu do sistema (P90). |
| D13 | `raio`, `raioOff` e `trocaRaio` no código sem campo na tela. | Raio por sede, como hoje. |
| D14 | Dicas marcadas com `data-tip`, que nenhuma biblioteca da pasta desenha. | Padrão de dica do sistema. |
| D15 | Tabelas fora da Folha sem listra e sem azul ao passar o mouse. | Seguir o desenho (só a Folha tem listra e azul). |
| D16 | Barra "Ver como" e "Próxima batida", nota "Dados de exemplo", equipe fictícia, folha de outubro importada. | Não entram: são do protótipo. |
| D17 | PDF da folha desenhado em imagem no navegador. | PDF gerado no servidor, em texto (como hoje), com o arranjo do desenho, código BJF e QR. Imagem só se o Samuel preferir. |
| D18 | Abonar esquecimento cria ajuste; hoje cria batida nova. | Segue a resposta do P65. |
| D19 | Aceite sem desfazer e sem regra para folha que muda depois. | Ver seção 3 (folha BJF): mostrar "alterada depois do aceite" e pedir nova assinatura. |
| D20 | QR para `https://www.bluejus.com.br/ponto/validar/CÓDIGO` (o site). | Endereço do sistema. |
| D21 | Feriados nacionais fixos no código do desenho. | Seguir o sistema (comando de carga dos nacionais por escritório). |
| D22 | Selo da Folha "Abonada" e da aba "Abonado" (gêneros diferentes). | Seguir o desenho em cada lugar. |

## 5. (c) Etapas em PRs pequenos, em ordem

**Regras para todas as etapas.** Só entra item aceito na seção 2. Componentes do desenho, com medidas e cores tiradas do
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
- Entra: P1 a P6, P8, P9, P12, P14 a P17, P13 e P19 se ficarem, P7 e P10 e P11 conforme a resposta.
- Depende de: aceite dos itens; nada técnico.
- Migration: não.
- Arquivos: `templates/ponto/index.html.twig`, `public/js/ponto-batida.js`, CSS novo da tela; `PontoController::index`
  (hora do servidor, última pendência). Frente jornada-vigencia: `PontoController.php` (o `index` foi mexido no §10.4);
  só leitura de `HistoricoDeJornadas` para D1. Avisar antes.
- Testes: funcional da `/ponto` com o arranjo do desenho (filho direto); `BotaoDoPontoRespondeAoToqueTest` e os do erro de
  GPS continuam verdes; a troca das 4 regiões depois da batida ("atualiza sem recarregar") continua tudo ou nada com os
  ids novos; `node --check` no JS.

**E2. Folha no molde do desenho.**
- Entra: P22 (sem "Assinar folha"), P23 a P28, P30 a P32 aceitos.
- Depende de: E1 (CSS e grade).
- Migration: não.
- Arquivos: `templates/ponto/_folha_table.html.twig` (também usado na ficha do colaborador: a ficha ganha o molde junto,
  ou o molde fica só na `/ponto`; decidir no aceite do P24). Frente: `PontoController::montarDadosFolha` só se o P23 for
  aceito (lista de meses). Avisar.
- Testes: `FolhaExportacaoMesmaEscolhaTest` e os da folha sem mudança de número; funcional do celular por classe; o "+"
  segue a regra escolhida no P30.

**E3. Cartão de abas: Pendências, Minhas Justificativas e Banco de Horas (dados de hoje).**
- Entra: P37, P38, P39, P40, P41, P42; aba Banco só com o saldo de hoje se o P51 for "b".
- Depende de: E1.
- Migration: não. Excluir a justificativa em análise é regra nova pequena (UseCase próprio, só o dono dela, só em análise,
  só no escritório dela; o anexo sai depois do commit).
- Arquivos: `_pendencias.html.twig`, `_justificativas_list.html.twig`, `PontoController.php` (rota de excluir; avisar),
  `NotificacaoService` (textos, se o P40 for aceito).
- Testes: unit do UseCase de excluir (pendente sim; analisada não; de outro usuário não; de outro escritório não);
  funcional 403 e 422; textos das situações.

**E4. Janelas no molde do desenho.**
- Entra: P44 a P47 aceitos (moldura do desenho, campos do sistema).
- Depende de: E1.
- Migration: não.
- Arquivos: `_justificativa_form`, `_justificativa_calendario`, `_justificativa_campos`,
  `_justificativa_esquecimento_modal`, `index.html.twig`. Nada da frente.
- Testes: os de justificativa de hoje continuam verdes; funcional com `role="dialog"`, Esc fecha, erro do formulário no
  lugar do desenho; abrir sozinho com `?esquecimento=`.

### Bloco 2: gestão sobre os dados de hoje

**E5. Aba Equipe.**
- Entra: P54, P55, P57, P58 (permissões de hoje), P56 conforme a resposta.
- Depende de: E3.
- Migration: não.
- Arquivos: controller e UseCase novos em `src/Ponto/` (situação da equipe por escritório; abono em lote chamando a regra
  do abono de hoje, uma por uma). Frente: leitura da jornada vigente; se o P56 for "a", passa a chamar
  `SalvarVersaoDaJornadaUseCase` (avisar e combinar antes).
- Risco: ALTO (ponto) e MÉDIO (permissão).
- Testes: unit da situação (em jornada, intervalo, sem registro, encerrada); funcional 403 para colaborador; isolamento
  por escritório; abono em lote não abona justificativa de outro escritório nem a própria (se P43 = b).

**E6. Configurações, parte 1: sedes e feriados na aba.**
- Entra: P68, P69, P70, P71, P72 aceitos.
- Depende de: E3.
- Migration: sim, só se a abrangência por sede (P71) for aceita (coluna no feriado).
- Arquivos: rotas de sedes de hoje (`TenantController.php`, avisar), `FeriadoController.php`, `CalculadoraJornada`
  (feriado por sede).
- Testes: feriado por sede vale só para quem é daquela lotação; sede sem coordenada continua ignorada; confirmação antes de
  remover (D4).

### Bloco 3: motor novo (cada um atrás da sua chave, nesta ordem)

**E7. Liga e desliga.**
- Entra: P67, P94 a P96 (e P93, decidido: usa a página "em preparação" e a faixa que o sistema já tem).
- Depende de: nada (pode correr junto do bloco 1).
- Migration: sim (configuração do Ponto por escritório). O catálogo do Master não precisa (chave sem linha vale ligada).
- Arquivos: `CatalogoDeRecursos.php` (tela `ponto` e as 8 funções); guarda de rota da tela: as rotas globais
  (`/ponto/estado-do-dia`, `/ponto/alerta-horario`, `/ponto/pendencia-do-dia`) e a página pública de validação não podem
  cair junto com a tela. Nada da frente (não engordar `JornadaTenant`, que é do PR 3 dela).
- Testes: Global desligado trava o Escritório; tela desligada mostra a página "em preparação" do sistema
  (`templates/recurso/em_preparacao.html.twig`) para quem não é Master e a faixa vermelha para o Master; padrão inicial do P96.

**E8. Tela cheia, aviso flutuante, sons, vibração e lembrete.**
- Entra: P79 a P83 (e P84 a P86 ficando).
- Depende de: E1, E7 (chaves `ponto-animacao`, `ponto-sons`, `ponto-lembrete`).
- Migration: não (o mudo fica guardado no aparelho, como no desenho).
- Arquivos: JS e CSS da tela. Nada da frente.
- Testes: funcional das fases por classe e `aria`; movimento reduzido; com a chave desligada o resultado vira aviso
  flutuante; o áudio do alerta de horário continua liberado.

**E9. Trilha de auditoria encadeada.**
- Entra: P64, P66 ficando.
- Depende de: E7.
- Migration: sim (tabela da trilha).
- Arquivos: serviço novo de trilha; chamadas nos fluxos de batida, justificativa, análise, configuração. Frente: se a
  mudança de jornada também entrar na trilha (o desenho grava "Escala alterada"), toca os 3 UseCases de versão (avisar).
- Testes: dois eventos ao mesmo tempo no mesmo escritório mantêm a cadeia; adulterar uma linha acusa "a cadeia foi
  alterada"; escritórios não se misturam; purga do escritório apaga a cadeia dele.

**E10. Batida só inserção, com ajuste.**
- Entra: P65 (se "a"), P29, D18.
- Depende de: E9.
- Migration: sim (tabela de ajuste).
- Arquivos: `TenantController.php` (editar e excluir batida), `RegistrarBatidaDoEsquecimentoUseCase`, reverter abono,
  `EscolhaDasBatidasDoDia`, `FolhaPontoBuilder.php`, `folha_pdf.html.twig` e `FolhaPontoXlsxExporter.php` (marca "aj").
  Frente: `FolhaPontoBuilder.php`, `TenantController.php`, `folha_pdf.html.twig` e o XLSX. Combinar antes.
- Testes: folha antes e depois igual quando não há ajuste; ajuste muda a folha e guarda o valor antigo; reverter desfaz;
  ninguém consegue apagar batida pela tela nem pelo "desfazer" da auditoria; mês com ajuste no PDF e no XLSX.

**E11. Alertas que não bloqueiam.**
- Entra: P21 (se "a").
- Depende de: E7 (`ponto-alertas`).
- Migration: não.
- Arquivos: `PontoController.php` (batida), textos da `_modal_jornada_tenant.html.twig` (frente, PR 3: avisar).
- Testes: retorno com repouso curto entra e a folha marca "não conforme"; entrada antes da interjornada entra com aviso;
  7º dia seguido avisa; o esquecimento continua sem travar (R1 a R4).

**E12. Fora do raio vira trabalho externo; raio com a precisão.**
- Entra: P18 (se "a"), P74.
- Depende de: E7, E9.
- Migration: sim (batida marcada como trabalho externo, com o motivo; justificativa ligada à batida).
- Arquivos: `PontoController.php`, `SedesDaCercaDoPonto`, `ponto-batida.js`.
- Testes: fora do raio sem a opção do escritório continua recusado; com a opção, entra marcado e cria a justificativa;
  precisão acima do teto pede nova leitura; home office continua sem localização.

**E13. Hora oficial e hora do aparelho.**
- Entra: P5 (se não entrou no E1), base do P20.
- Depende de: E7.
- Migration: sim (hora do aparelho, hora de chegada, marca de envio sem internet, identificador único do envio).
- Arquivos: `RegistroPonto`, `PontoController.php`, `ponto-batida.js`. Fora do código: conferir o NTP da VPS (comando
  para o Samuel).
- Testes: batida grava as duas horas; o mesmo identificador enviado duas vezes não cria duas batidas.

**E14. Fila sem internet.**
- Entra: P20 (se "a").
- Depende de: E13.
- Migration: não (usa as colunas do E13).
- Arquivos: `ponto-batida.js` (fila cifrada, envio ao voltar a internet).
- Testes: JS da fila (`node --check` e teste do envio); servidor recusa batida guardada além do teto de idade; a folha e o
  gestor veem "enviada sem internet".

**E15. Comprovante, aba Comprovantes e validação.**
- Entra: P48, P49, P50, D2, D20.
- Depende de: E7 (`ponto-comprovante`), E9, E13.
- Migration: sim (comprovante e contador do NSR por escritório).
- Arquivos: controller novo; rota pública (precisa liberar o caminho em `security.yaml`, arquivo quente: avisar); segredo
  novo do HMAC (o Samuel cria em produção); biblioteca do QR local.
- Testes: NSR sem buraco com batidas ao mesmo tempo; código errado e inexistente com a mesma resposta; a página pública
  não mostra CPF, localização nem o nome inteiro (se decidido); limite de tentativas.

**E16. Folha com código BJF, PDF novo e aceite mensal.**
- Entra: P22 ("Assinar folha"), P33 a P36, P87 a P89, D17, D19.
- Depende de: E7 (`ponto-aceite`), E9, E15; de preferência depois do E10.
- Migration: sim (folha emitida e aceite).
- Arquivos: `folha_pdf.html.twig`, exportação do PDF no `PontoController.php`, `FolhaPontoXlsxExporter.php` se o código
  for também no XLSX. Frente: é a pendência 2 dela ("Jornada alterada em dd/mm" no cabeçalho). Combinar quem entra primeiro.
- Testes: código da folha validado pela página pública; aceite só de mês fechado; ressalva sem texto recusada; PDF sem
  pendência (se P35 = b); `FolhaExportacaoMesmaEscolhaTest` verde.

**E17. Banco de horas por lotes.**
- Entra: P51 (se "a"), P52, P53, P73, P2 completo.
- Depende de: E7 (limite e prazo na configuração), decisão do dono.
- Migration: não, se os lotes forem calculados; sim, se o dono quiser fechar lotes à mão.
- Arquivos: `FolhaPontoBuilder.php` (`calcularSaldoAnual`; frente: avisar), serviço novo de lotes.
- Testes: crédito mais antigo abatido primeiro; vencimento no último dia do mês "competência mais prazo"; horas pagas no
  lote certo; saldo de hoje igual ao de antes quando o dono mantém o zerar em janeiro.

**E18. Reconhecimento facial.**
- Entra: P76, P77, P78, P15 (variante facial).
- Depende de: fornecedor contratado, jurídico, E7 (`ponto-facial`, desligada), E9, E15.
- Migration: sim (cadastro facial, autorização e recusa; foto opcional com retenção).
- Arquivos: integração com o fornecedor, armazenamento privado da foto, purga pelo agendador (configuração do agendador
  é arquivo quente: avisar).
- Testes: sem autorização não há facial e a batida segue pelos outros meios; recusa sem penalidade; excluir cadastro apaga
  aqui e no fornecedor; foto some no prazo; facial obrigatória sem câmera explica e não trava sem saída.

## 6. (d) Migrations necessárias

Número de cada uma: a pedir ao Coordenador. Recomendação geral: reaproveitar as tabelas que existem
(`registro_ponto`, `justificativa_ponto`, `sede`, `feriado`, `jornada_*`), sem renomear: renomear não traz ganho e mexe
em todo o Ponto e na frente jornada-vigencia.

| Tabela do ESPEC | Hoje | Situação | O que fazer | Etapa |
|---|---|---|---|---|
| `ponto_batida` | `registro_ponto` | Já existe com outro nome | Reaproveitar. Colunas novas: marca de trabalho externo e motivo (E12); hora do aparelho, hora de chegada, marca "enviada sem internet", identificador único do envio (E13); método (sistema ou facial) (E18) | E12, E13, E18 |
| `ponto_ajuste` | Não existe (o gestor faz UPDATE e DELETE) | Precisa criar, se o P65 for "a" | Tabela nova: escritório, pessoa, batida (vazia na inclusão), data, tipo, valor anterior, valor novo, autor, criado em, motivo, justificativa ligada | E10 |
| `ponto_justificativa` | `justificativa_ponto` | Já existe com outro nome | Reaproveitar. Coluna nova opcional: batida ligada (trabalho externo) | E12 |
| `ponto_comprovante` | Não existe | Precisa criar | Tabela nova, uma linha por batida: NSR, código, HMAC, emitido em, dados assinados; mais um contador de NSR por escritório (linha travada na gravação) | E15 |
| `ponto_folha_aceite` | Não existe | Precisa criar | Escritório, pessoa, ano, mês, situação (concordo ou ressalva), texto da ressalva, aceito em, IP, navegador, folha emitida vista; único por pessoa, escritório e mês | E16 |
| (folha emitida, fora da lista do ESPEC) | Não existe | Precisa criar | Código BJF, HMAC, escritório, pessoa, competência, emitida em, emitida por, resumo do conteúdo | E16 |
| `ponto_sede` | `sede` (legado, `src/Entity/Tenant/Sede.php`: latitude, longitude, raio, fuso) | Já existe com outro nome | Reaproveitar; o padrão do raio só muda com o P70 | nenhuma |
| `ponto_feriado` | `feriado` (por escritório, recorrente ou pontual) | Já existe com outro nome | Reaproveitar. Coluna nova: sede opcional (abrangência) | E6 |
| `ponto_escala` | `jornada_colaborador` e `bloco_jornada_colaborador` (com vigência), `jornada_tenant` e `bloco_jornada` | Precisa decisão (P56) | Não criar. Se as 4 escalas forem aceitas, viram modelos que preenchem os blocos de uma versão nova | nenhuma |
| `ponto_banco_lote` | Não existe; há `ponto_lancamento_horas_pagas` | Precisa decisão (P51) | Calcular os lotes a partir da folha, sem tabela; tabela só se o dono quiser fechar lotes à mão | E17 |
| `ponto_config_escritorio` | Parcial: `jornada_tenant` (validações e alertas), `home_office_config` (por pessoa) | Precisa criar | Tabela nova, uma por escritório: 8 chaves do escritório, modo facial, guardar foto, meses de retenção, limite e prazo do banco, permitir trabalho externo, raio padrão. Não engordar `jornada_tenant` (PR 3 da frente) | E7 |
| `ponto_auditoria` | `audit_log` genérico, sem encadeamento | Precisa criar | Escritório, em, autor, ação, detalhe, selo anterior, selo; índice por escritório e data | E9 |
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

- 09/11/2026: fase 1 entregue (esta spec, só análise, nenhum código); aguardando aceite do Samuel item a item na seção 2.
- Próximo passo: com as respostas, marcar cada P como aceito ou não, fechar as 13 (PERGUNTA) e os pontos D1 a D22, e só
  então abrir a E1 (e a E7, que pode correr junto).
- Nenhuma migration pedida ainda. Nenhum arquivo da frente jornada-vigencia tocado.
