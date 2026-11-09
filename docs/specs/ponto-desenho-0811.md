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
- **P30. (PERGUNTA)** "+" do esquecimento. Hoje: "+" na batida que falta, só em dia útil passado que tem alguma batida; ampulheta quando já pediu. | No desenho: "+" em qualquer dia passado da folha; hoje e feriado mostram um traço. | Origem: decisão do dono de 28/07 (spec ponto-atalho-esquecimento-registro). | Opções: (a) desenho; (b) regra de hoje com o "+" no visual do desenho e a ampulheta. Recomendo (b). | Decisão: (a) (contra a recomendação acima). "+" como o desenho; "Em análise" com ampulheta fica na coluna Justificativa.
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
- **P43. (PERGUNTA)** Gestor abonar a própria justificativa. Hoje: o gestor analisa na ficha de cada colaborador; analisar a própria está em aberto para o dono. | No desenho: na aba "Minhas Justificativas", o usuário primário e o Master veem "Abonar" e "Recusar" nas próprias justificativas. | Origem: spec ponto-edicao-justificativa-analisada (§6, em aberto). | Opções: (a) desenho; (b) ninguém analisa a própria; os botões aparecem só na aba Equipe, para as dos outros. Recomendo (b). | Decisão: (a) (contra a recomendação acima). O primário e o Master veem "Abonar" e "Recusar" nas próprias justificativas, como o desenho.
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
6. Banco por lotes (P51 a): não zera mais em janeiro; as horas pagas seguem no saldo. Continua a definir na E17: o saldo
   de antes do início dos lotes ("Sem data de origem").
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
  - o aviso do intervalo (P12) segue a chave `validacaoRepousoHabilitada` da Jornada do escritório: desligada, não
    aparece;
  - as setas do teclado andam entre os tipos livres como num grupo de rádio (tabindex móvel); o desenho só tem o Esc;
  - o botão de som e o de mudo (P81) vão para a E8;
  - os parâmetros de leitura do GPS são os do sistema (spec `ponto-gps-erro-que-explica`), não os do protótipo;
  - a saída prevista usa a escala: antes do repouso batido, entrada mais a duração da escala de hoje (com o almoço);
    depois dele, entrada mais a meta do dia e o intervalo (o batido, ou o mínimo da Jornada do escritório);
  - os avisos que mandavam olhar "a lista abaixo" apontam para os horários nos botões dos tipos, acima.
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
  batida some da tela e continua descontando do saldo), P26, P27, P28 (fica), P30 (a: "+" em qualquer dia passado; hoje
  e feriado sem "+"; "Em análise" com ampulheta na coluna Justificativa), P31 ("Não abonada" e o horário do abono
  parcial ficam), P32 (fica), D7, D15, D22.
- Decisões da etapa (09/11/2026, Coordenador e implementação):
  - a ficha do colaborador ganha o mesmo molde e mostra todos os dias do mês; o filtro do P25 é só da `/ponto`
    (parâmetro `somenteDiasComBatida`). A ficha carrega o CSS da folha pelo próprio partial;
  - ficam do sistema, no estilo do desenho: o selo da jornada na barra da ficha ("40h00m/sem (Personalizada)"), o sinal
    de registro em outro escritório, a dica do "Registro incompleto", "A conferir", "Home Office", as batidas fora da
    conta do gestor, "Ver PDF" (P89, ainda no modal do sistema até a E16/E4) e "Baixar XLSX" (P88, agora baixa direto);
  - o "+" (P30 a) vale em qualquer dia passado com batida, inclusive fim de semana; hoje, feriado e dia antes do início
    da contagem ficam sem ele. A batida que já tem esquecimento pendente ou abonado também fica sem "+" (condição 8 do
    sistema, para não criar duplicata); o "Em análise" aparece na coluna Justificativa;
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
    entra no rodapé sem aparecer em linha nenhuma. O número certo é o do rodapé, que é o de antes;
  - com a lista do P23 e o "+" do P30 a, o atalho do esquecimento aparece em meses antigos; o servidor já aceitava
    qualquer dia passado pelo modal completo e o sistema não tem mês fechado, então não há exposição nova;
  - os números são os do motor: o rodapé e o "Saldo mês" de cada linha vêm das linhas sem o filtro; o PDF, o XLSX e
    `montarDadosFolha` não mudaram.
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
  sem "+"), `HorasPagasFolhaExibicaoTest` (cabeçalho "Saldo mês" e marcação do rodapé), `JornadaComVersoesPelaTelaTest`
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
- Decisões da etapa (implementada em 09/11/2026, branch `ponto-desenho-e3`, empilhada na E2):
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
    (subtítulo "Parcial: 08:00 às 12:00", P45). O "Motivo" do desenho (L184) não entra: a justificativa não tem o
    campo (P44, E4; entrou na E4, com a coluna nova). Obs. da análise em análise: "Aguardando análise"; "Analisado em" vazio (seção 8);
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
    (abre com hoje do servidor marcado; dia futuro desligado), depois o tipo (os tipos de hoje, lista plana como a
    tela antiga), o aviso da falta não justificada, o bloco do esquecimento (horário com segundos), o abono parcial,
    "Motivo" (obrigatório) e "Atestado ou documento (opcional)" (`accept` do servidor: PDF, JPG ou PNG). A conferência
    antes do envio põe a frase na linha de erro: "Informe a data e o motivo." (do desenho) e as do servidor; o alerta
    do navegador do abono com vários dias virou a linha de erro;
  - "Registro de esquecimento" (P46): resumo "dd/mm/aaaa · Saída", "Horário" com segundos (`with_seconds` no
    `JustificativaPontoType`; "14:32" vira 14:32:00) e "Motivo"; "Informe o horário e o motivo." A dica de dentro da
    janela saiu; o aviso flutuante "Enviado para aprovação do gestor." fica para a E8 (P80). `?esquecimento=` continua
    clicando o "+" da folha, agora sem esperar o Bootstrap;
  - "Editar justificativa" (P47): os campos de hoje na moldura nova, mais o "Motivo" (a edição recusa motivo em branco
    ou longo demais; pedido sem o campo não mexe no motivo). O horário do esquecimento na edição segue em minutos. O
    lápis abre por delegação (`data-justificativa-editar`), sem `onclick`;
  - o motivo aparece na aba "Minhas Justificativas" abaixo do tipo (L184) e, na ficha do gestor, uma linha "Motivo" no
    resumo do lote (decisão da implementação: quem analisa precisa ver o que foi escrito). Os parciais da ficha
    (`_justificativa_calendario`, `_justificativa_campos`) ficaram intactos;
  - achado da revisão da E3: depois da edição (salva ou recusada), a tela volta na aba "Minhas Justificativas", no mês
    da justificativa (`PontoController::voltaDaEdicao`, `?competencia=AAAA-MM&aba=justificativas`);
  - motivo: até 2000 caracteres (`JustificativaPontoType::MOTIVO_MAXIMO`), sem os espaços das pontas, o mesmo em cada
    dia do lote.
- Arquivos: `_justificativa_form`, `_justificativa_esquecimento_modal`, `_abas_justificativas`, `index.html.twig`,
  `tenant/_justificativas_tab`, `public/js/ponto-abas.js`, `public/js/ponto-janelas.js` (novo),
  `public/css/ponto-eletronico.css` (campos das janelas e calendário), `JustificativaPonto`, `JustificativaPontoType`,
  `PontoController` (motivo, segundos, `voltaDaEdicao`), a migration e o e2e `ponto-atalho-cabecalho.spec.js`.
- Testes: `JanelasDoPontoTelaTest` (moldura por filho direto nas três janelas, nada do Bootstrap, campos e ordem,
  motivo na aba e no lápis, motivo de outro escritório não aparece, `?esquecimento=` com o "+", motivo na ficha),
  `MotivoDaJustificativaControllerTest` (motivo em cada dia do lote; sem, em branco e longo: nada nasce; segundos;
  edição grava, recusa e volta na aba; de outra pessoa: 403), `PontoJanelasJsTest`, `JanelasDoDesenhoCssTest`;
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

**E6. Configurações, parte 1: sedes e feriados na aba.**
- Entra: P68, P69 (fica: mapa e busca por CEP ou endereço), P70 (a: sede nova nasce com 150 m; as sedes de hoje não
  mudam), P71 (abrangência por sede), P72 (fica), D4, D13, D21.
- Depende de: E3.
- Migration: sim (coluna sede opcional no feriado, P71).
- Arquivos: rotas de sedes de hoje (`TenantController.php`, avisar), `FeriadoController.php`, `CalculadoraJornada`
  (feriado por sede).
- Testes: feriado por sede vale só para quem é daquela lotação; sede sem coordenada continua ignorada; confirmação antes de
  remover (D4); sede nova com 150 m e as que já existem com o raio delas.

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
- Depende de: E7 (limite e prazo na configuração). O saldo de antes do início dos lotes ("Sem data de origem", seção 3)
  se fecha com o Coordenador ao abrir a E17.
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

Número de cada uma: a pedir ao Coordenador. Recomendação geral: reaproveitar as tabelas que existem
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
| `ponto_auditoria` | `audit_log` genérico, sem encadeamento | Precisa criar | Escritório, em, autor, ação, detalhe, selo anterior, selo; índice por escritório e data. Só os eventos que o desenho grava (E9); mudanças de configuração (sede, feriado, limite, prazo, modo facial, foto) ficam no `audit_log` (D5 não) | E9 |
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
- E3 implementada na branch `ponto-desenho-e3` (empilhada na E2), sem migration, em parciais novos (a tela antiga fica
  intocada). Decisões e os pontos de liga e desliga do P40, P43 a e P41 registrados na E3 (seção 5).
- E13 integrada na branch `ponto-desenho` (frentes `ponto-desenho-e13`, servidor, e `ponto-desenho-e13-tela`), com a
  migration `Version20261109220000`. Decisões da etapa registradas na E13 (seção 5).
- E4 implementada na branch `ponto-desenho-e4` (empilhada na E3), com a migration `Version20261109210000` (coluna
  `motivo` em `justificativa_ponto`). Decisões registradas na E4 (seção 5).
- Próximo passo: PR da E1 e smoke do dono na tela; depois o PR da E2. A E7 pode correr junto.
- Pontos que se fecham com o Coordenador ao abrir a etapa: chave do selo da trilha (E9), teto de idade da fila (E14),
  saldo de antes dos lotes (E17).
- Pendências do dono: seção 10. Nenhuma trava a E1.
- Migrations: `Version20261109210000` (E4).

## 10. Pendências do dono e ganchos

Nenhuma destas trava o código: cada etapa entra pronta com o gancho abaixo e passa a valer quando o dono resolver.

**Chaves por finalidade (decidido pelo Coordenador em 09/11/2026).** O dono cria em produção um segredo só, o
`PONTO_HMAC_SECRET`. O código nunca usa o segredo direto: deriva uma chave por finalidade com
`hash_hkdf('sha256', $segredo, 32, '<finalidade>')`. Finalidades: `ponto-comprovante` (comprovante da batida, E15),
`ponto-folha` (folha emitida, E16) e `ponto-trilha` (selo da trilha encadeada, E9). Cada registro selado guarda a versão
da chave usada (começa em 1), para que uma troca futura do segredo não quebre a conferência do que já foi selado: a
conferência escolhe a chave pela versão gravada na linha.

| Pendência (itens) | Por que é do dono | Gancho no código | Etapa que precisa |
|---|---|---|---|
| Conferir o relógio (NTP) da VPS (P5) | Produção; o Claude não entra na VPS | Nenhum gancho novo: a batida e a "Hora oficial" já usam o relógio do servidor. O comando de conferência (por exemplo, `timedatectl status`, linha "System clock synchronized") vai ao Samuel em bloco para o terminal externo | E13 (e a linha "Hora oficial" da E1) |
| Criar em produção o segredo do HMAC do comprovante e da folha (P34, P48, P50) | Credencial | Variável de ambiente `PONTO_HMAC_SECRET`, declarada vazia no `.env` do repositório (só o nome). Vazia: a batida grava com NSR, nenhum comprovante nem folha com código é emitido, a aba Comprovantes, o "Validar código" e o "Assinar folha" não aparecem, e nada quebra. Preenchida: a emissão começa sozinha | E15 e E16 (a E9 pode usar a mesma; ver a E9) |
| Contratar o fornecedor da biometria facial com prova de vida (P76, P78, D10) | Contratação | Interface de fornecedor (cadastrar, conferir com prova de vida, excluir cadastro) com um dublê de teste; chave `ponto-facial` desligada até o contrato. O fornecedor real entra depois, num PR pequeno que só implementa a interface | E18 |
