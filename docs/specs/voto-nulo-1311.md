# Voto nulo: contrato e inventário

O participante confirma um número inexistente, vendo NÚMERO ERRADO e VOTO NULO, ou usa CORRIGE.
Pré-condições: acesso válido, unidade apta, pauta aberta e ainda sem participação. Registro inteiro numa transação.
O desenho (consulta/voto.dc.html L265/L343 e bj-voto-nucleo.js L35/L37) conta cada número inexistente como uma
escolha nula. Válido e nulo podem coexistir até o limite de vagas, mantendo uma participação e o peso presente uma vez.
Repetidos, tamanho errado, número existente enviado como nulo, abstenção misturada e excesso são recusados antes do INSERT.
Pós-condição: participação e comprovante usuais; contadores de opções e nulos atualizados; sem vínculo identidade-escolha.

## Persistência e compatibilidade

RegistrarVotoInput mantém opcoesIds e ganha numerosNulos=[] (strings com zeros). Nenhum número nulo fica persistido.
Dois contadores agregados na pauta, nulos_unidades e nulos_peso, com default zero em migration aditiva.
SQL direto, sem auditoria das escolhas. Toda participação toca a pauta e todas suas opções, inclusive somando zero,
para que xmin não identifique voto nulo. INSERT antes dos contadores, mesmo lock e UNIQUE de participação.
Leituras finais recarregam contadores. Nulos não são válidos, mas contam em presença e quórum; várias escolhas não
multiplicam presença. Totais da tabela de escolhas incluem nulos e seus pesos. Comprovante não revela tipo ou número.

## Inventário e validação

| Região | Desenho | Código e teste previsto |
| --- | --- | --- |
| Visor | NÚMERO ERRADO, clamp(20px,3vw,28px), bold | cabine JS/CSS |
| Visor | VOTO NULO, clamp(30px,5vw,52px), bold | cabine JS/CSS |
| Rodapé | confirmação/correção ao completar número | estados JS e Node |
| Leitor de tela | Número errado, voto nulo. Confirme ou corrija. | tradução e JS |
| Várias vagas | nulo ocupa uma escolha, número repetido não avança | JS/UseCase/HTTP |
| Apuração | quantidade e peso de nulos; presença uma vez | unitário e banco |
| Resultados/cédula/ata | Nulos real no lugar do zero fixo | funcional HTML |
| Sigilo | sem tipo/número na participação; UPDATE de todas linhas | banco, auditoria e xmin |

Preservar funções existentes, alternativa sem JS e simulação sem gravação. Nenhuma alteração de autorização ou identidade.
Conferência visual e smoke no navegador pertencem ao Samuel.

## Conferência final contra código

| Item | Resultado |
| --- | --- |
| NÚMERO ERRADO e VOTO NULO | ✓ textos literais e medidas clamp do desenho |
| Confirmar ou corrigir | ✓ número completo confirma; incompleto não avança |
| Repetição | ✓ bloqueio e aviso no visor e no leitor de tela |
| Várias vagas | ✓ válidos e nulos coexistem; cada nulo ocupa uma vaga |
| Presença e quórum | ✓ uma participação, nulos fora dos válidos |
| Peso e totais | ✓ agregados com bcmath, incluindo os nulos |
| Resultado da cédula, gestor e ata | ✓ quantidade/peso reais; zero fixo retirado |
| Sigilo e comprovante | ✓ sem número/tipo na participação; SQL sem auditoria de escolha |
| xmin | ✓ pauta e todas opções tocadas, até no nulo puro |
| Alternativa sem JS | ✓ formulário próprio na vaga única, campos no formulário de várias vagas |
| Simulação | ✓ conserva caminho sem envio da cabine |

Migration gerada por doctrine:migrations:diff, pois make:migration não existe no ambiente test.
O diff foi limpo contra /tmp/voto-nulo-divergencia-antes.txt: ficaram somente duas colunas aditivas.
A migration foi aplicada somente no banco saas_testvoto-nulo. Pautas antigas começam com zero nulos.
Conferência visual pelo Samuel permanece pendente.

Validação local: Voto com 346 testes e 1.761 asserções; Voto e verificações de arquitetura passaram em rodada
conjunta com 383 testes e 1.922 asserções antes de acrescentar o teste do formulário sem JavaScript.
Cinco testes Node, sintaxe JS, PHPStan, estilo da migration, 25 Twig e 1 YAML aprovados.
Revisão e re-revisão read-only aprovadas após corrigir o aviso acessível de número nulo repetido.
