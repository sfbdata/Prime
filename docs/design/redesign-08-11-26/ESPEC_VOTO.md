# BlueJus Voto: viabilidade, arquitetura e regras para o sistema real

Versão de 08/11/2026. Este documento separa o que já funciona, o que é protótipo e o que depende de terceiros.

## 1. Situação atual

Entregue no protótipo (`18 - Voto`, `bj-voto-nucleo.js`, `bj-voto-testes.js`):

- Gestão de eleições, cargos, candidaturas, ordem, publicação, abertura, encerramento e apuração.
- Habilitação com carteira OAB, documento, selfie, consentimento, PIN e análise humana.
- Cabine com fluxo inspirado na urna, teclado físico e de tela, sons discretos e confirmação final.
- Protocolo com criptografia real, testes automáticos aprovados e verificador independente.

O protótipo não pode conduzir eleição real. Todos os serviços rodam no mesmo navegador e o estado fica em localStorage, editável por quem controla o aparelho. A separação entre serviços existe no código e nas chaves de armazenamento, não em infraestrutura. Nenhum sistema é 100% confiável: o objetivo em produção é tornar qualquer fraude detectável e cara, com verificação por terceiros.

O backend real do BlueJus não foi analisado, pois não houve acesso a ele nesta etapa. A seção 8 define como integrar sem risco, mas o levantamento do banco, da autenticação e das permissões reais continua pendente.

## 2. Referência jurídica: cenário de entidades de classe

O produto é neutro e não cita entidade específica nas telas. Esta seção documenta um cenário possível.

Conclusão: viável como plataforma para eleições institucionais e consultas privadas. Para eleição oficial da OAB, depende de contratação pela Seccional, das regras vigentes do ano da eleição e, se repetido o modelo de 2024, de credenciamento pelo Conselho Federal.

| Tema | Regra localizada | Fonte | Efeito no projeto |
|---|---|---|---|
| Modalidade | Votação presencial ou on-line, a critério do Presidente do Conselho Seccional, ad referendum da Diretoria | Provimento 222/2023, art. 25 | Quem decide é a Seccional, não o BlueJus |
| Autenticação | Senha pessoal e intransferível ou certificação digital | Prov. 222/2023, art. 26, § 1º, II | PIN isolado não atende ao edital abaixo |
| Aptidão | Inscritos e adimplentes; licenciados excluídos; voto único na inscrição principal, salvo opção pela suplementar | Prov. 222/2023, art. 26, § 1º, I e V | Exige base oficial da OAB |
| Lista de aptos | Providenciada pela Comissão Eleitoral | Prov. 222/2023, art. 26, § 1º, VIII | A lista vem da Comissão |
| Auditoria | Equipe de auditoria obrigatoriamente contratada; chapas podem credenciar fiscais | Prov. 222/2023, art. 26, § 1º, XI; art. 27, § 1º | Auditoria externa é requisito |
| Credenciamento 2024 | CFOAB credenciou empresas; Seccionais contrataram depois | Edital 01/2024-CFOAB | Caminho comercial, se repetido |
| Identificação 2024 | Certificado digital, ou login e senha com duplo fator (código de segurança da carteira, senha temporária ou biometria facial 1 para 1 com prova de vida) | Edital 01/2024, item 4.1.6 | Duplo fator é obrigatório |
| Sigilo e integridade 2024 | Anonimato, integridade, logs com proteção criptográfica, criptografia ponta a ponta com certificado ICP-Brasil | Edital 01/2024, itens 4.1.8 a 4.1.12 e 4.1.21 | Base da seção 4 |
| Capacidade 2024 | Atestado de ao menos 400.000 votos em 8 horas | Edital 01/2024, item 5.1.1 | BlueJus ainda não possui atestado |

Verificar antes de qualquer proposta: Retificações I e II do Edital 01/2024 (não lidas); regras para o próximo pleito; vigência do Regulamento Geral, art. 134, § 6º, que na cópia de 2018 manda aplicar ao voto eletrônico, no que couber, a legislação eleitoral. O Provimento 208/2021 exigia referendo do Conselho Federal, mas alterava o Provimento 146/2011, revogado pelo 222/2023; entender que o referendo caiu é inferência, não texto expresso.

### O que é obrigação e o que não é

| Categoria | Conteúdo | Situação |
|---|---|---|
| Conformidade legal | LGPD (dado biométrico é sensível, exige base legal específica e relatório de impacto; citação não conferida nesta etapa), Estatuto da Advocacia, Regulamento Geral e provimento vigente | Parecer jurídico pendente |
| Requisitos de cada eleição | Edital, regulamento da entidade, lista de aptos, regras da Comissão | Configurável por eleição |
| Boas práticas de segurança | Normas ISO/IEC 27001, OWASP ASVS, testes de intrusão, gestão de chaves em HSM | Adotar; não são certificação automática |
| Requisitos técnicos de auditoria | Registros verificáveis, cerimônias públicas, código inspecionável, pacote público de verificação | Parcialmente implementado |
| Certificações | ICP-Brasil para certificados; credenciamento CFOAB; certificações de segurança, se exigidas | Não obtidas |

Normas do TSE: a Resolução 23.673/2021, alterada pela 23.728/2024, trata da fiscalização e auditoria do sistema eletrônico de votação da Justiça Eleitoral. Não foi localizada norma que a torne aplicável às eleições da OAB ou a votações privadas. O sistema pode adotar práticas equivalentes como referência voluntária. O BlueJus não pode afirmar certificação do TSE, auditoria oficial ou equivalência jurídica à urna eletrônica.

## 3. Arquitetura de domínios

Cada domínio é um serviço, banco e credencial próprios. Nenhum serviço acessa o banco de outro.

| Domínio | Conhece | Nunca conhece |
|---|---|---|
| Identidade | Dados pessoais, documentos, autenticação | Token e cédula |
| Elegibilidade | Lista de aptos da Comissão | Cédula |
| Autorizador | Pseudônimo de quem participou, faixa horária | Token em claro, conteúdo do voto |
| Urna | Token, assinatura, cédula cifrada, cadeia de hash | Identidade, pseudônimo |
| Guardiões | Partes da chave | Identidade |
| Apuração | Cédulas embaralhadas | Identidade |
| Auditoria | Eventos administrativos em cadeia | Conteúdo do voto |

Fluxo do voto:

1. O eleitor se autentica no domínio Identidade e a elegibilidade é confirmada.
2. O aparelho gera um token aleatório e o cega. O Autorizador registra a participação e assina sem ver o token (assinatura cega RSA).
3. O aparelho retira o cegamento e cifra a cédula com a chave pública da eleição, em tamanho fixo.
4. A urna valida a assinatura, recusa token repetido e anexa a cédula à cadeia de hash.
5. Encerrada a votação, a chave de assinatura é descartada e a cabeça da cadeia é registrada.
6. Guardiões em quórum liberam a chave, as cédulas são embaralhadas e totalizadas.
7. O pacote público permite a qualquer auditor refazer cadeia, assinaturas, decifração e contagem.

A administração não tem função para ler cédula individual nem para gravar resultado. O resultado só nasce do processo de apuração, e o pacote público denuncia qualquer divergência.

## 4. Implementado e limite de cada mecanismo

| Mecanismo | No protótipo | Exigência para produção |
|---|---|---|
| Assinatura cega | RSA 2048 com hash de domínio completo, escrito em BigInt | Biblioteca auditada (RFC 9474), chave em HSM |
| Cifra da cédula | ECDH P-256, HKDF, AES-GCM, tamanho fixo de 512 bytes | Revisão criptográfica externa; cifra homomórfica ou mistura verificável |
| Guardiões | Shamir 3 de 5; a chave é gerada e reconstruída em um ponto | Geração distribuída e decifração em limiar, sem reconstrução |
| Verificação | Cadeia de hash, recontagem por pacote | Provas de conhecimento zero de decifração ou embaralhamento (modelo ElectionGuard ou equivalente), publicação externa da cabeça da cadeia |
| Voto como pretendido | Não resolvido: aparelho comprometido pode alterar o voto antes de cifrar | Desafio de cédula (Benaloh) e canal de código de retorno |
| Coerção | Comprovante não revela o voto; não impede alguém de observar o eleitor | Risco inerente ao voto remoto; tratar em regulamento e, se admitido, permitir voto substituível |
| Correlação por tempo | Sem horário na urna; participação só por faixa, em ordem alfabética | Lotes com atraso aleatório antes da publicação |
| Autenticação | PIN com PBKDF2 e bloqueio após 5 erros | Duplo fator conforme edital, certificado ICP-Brasil ou serviço de identidade |
| Comprovante | Código de depósito na urna | Comprovante de participação com data, hora e IP (edital, item 4.1.9) emitido pelo Autorizador, nunca pela urna |
| Validação da OAB | Conferência humana e consulta manual ao CNA | Integração oficial com a base da OAB |
| Biometria | Não há comparação automática | Fornecedor com prova de vida; alternativa sem biometria para quem não consentir |
| Incidentes | Registro de fraude detectada | Motor de anomalias e equipe de resposta |

## 5. Dependências externas

| Dependência | Tipo | Bloqueia |
|---|---|---|
| Acesso à base oficial e à lista de aptos da OAB | Convênio | Eleição oficial |
| Credenciamento CFOAB ou contratação pela Seccional | Institucional | Eleição oficial |
| Fornecedor de identidade com prova de vida | Contratação | Biometria |
| Certificado ICP-Brasil e, se usado, credenciamento gov.br | Contratação | Autenticação forte |
| HSM ou KMS dedicado | Contratação | Produção |
| Auditoria externa e teste de intrusão independentes | Contratação | Produção |
| Revisão criptográfica independente | Contratação | Produção |
| Nuvem multi-região, WAF e proteção contra DDoS | Contratação | Escala |
| Encarregado de dados, relatório de impacto e parecer jurídico | Interno | Produção |

## 6. Riscos principais

| Risco | Impacto | Tratamento |
|---|---|---|
| Afirmar confiabilidade ou certificação inexistente | Reputacional e jurídico | Aviso permanente; revisão de todo material de divulgação |
| Protótipo usado em eleição real | Crítico | Manter aviso e bloquear uso até concluir o roteiro |
| Falso cadastro por foto de terceiro | Alto | Prova de vida, base oficial, duplo fator |
| Aparelho do eleitor comprometido | Alto | Desafio de cédula, código de retorno |
| Administrador com acesso a vários domínios | Crítico | Segregação, quórum, HSM, trilha externa |
| Indisponibilidade no horário de pico | Alto | Filas, testes de carga, janela ampliada |
| Vazamento de biometria | Crítico | Guardar modelo, não foto; retenção mínima; exclusão automática |
| Divergência na apuração | Crítico | Bloqueio automático e recontagem por pacote público |

## 7. Escalabilidade

Medido em 08/11/2026 no Node 22, contêiner com 2 vCPU, um único processo, armazenamento em memória, sem rede e sem banco, com PBKDF2 reduzido a 1.000 iterações:

| Medida (2.000 eleitores) | Resultado |
|---|---|
| Fluxo completo do voto | 6,2 ms, 161 votos por segundo |
| Verificação integral da urna | 0,87 s |
| Apuração | 1,65 s |
| Verificador independente | 1,68 s |

Isto não comprova capacidade para milhões de eleitores. Mede apenas o custo criptográfico e serve de linha de base.

Dimensionamento alvo (janela de 8 horas, pico de 10 vezes a média):

| Eleitores | Média | Pico |
|---|---|---|
| 400.000 | 14 votos/s | 139 votos/s |
| 1.000.000 | 35 votos/s | 347 votos/s |
| 5.000.000 | 174 votos/s | 1.736 votos/s |

Arquitetura: CDN para a cabine, autenticação sem estado, sala de espera, Autorizador particionado por hash do pseudônimo, banco com restrição única de pseudônimo por eleição, urna particionada em fragmentos com cadeia própria unidos por árvore de Merkle, fila durável com escrita confirmada e idempotência por token, réplicas síncronas em três zonas, RPO zero para urna, restauração ensaiada, apuração paralela.

Critérios de aceite, a demonstrar antes de qualquer afirmação comercial:

- Carga sustentada de 8 horas no volume alvo e rampa até 3 vezes o pico, com p99 abaixo de 2 s.
- Concorrência: zero votos duplos e zero cédulas perdidas sob falha injetada.
- Queda de zona durante o pico, sem perda de voto confirmado.
- Ataque simulado de negação de serviço, com votação mantida.
- Restauração completa dentro do tempo definido.
- Relatório emitido por terceiro independente.

## 8. Proteção do BlueJus existente

- Módulo aditivo. No protótipo: novos arquivos, chaves `bj-votacao-*`, um link de menu nas telas de Ponto e Assinatura e uma entrada em `bj-recursos`.
- Em produção: esquemas e bancos próprios, deploy separado, ativação pela chave `voto` de `recurso_estado`, sem migração destrutiva e sem alteração de tabelas existentes.
- O BlueJus fornece somente login e menu. A identidade eleitoral é confirmada no módulo Voto.

## 9. Testes

Executáveis em Gestão eleitoral, Testes, ou no Node. Cobrem voto duplo, simultaneidade, autorização falsificada ou reutilizada, cadastro duplicado, bloqueio por PIN, período, quórum de guardiões, parte adulterada, separação de identidade, tamanho fixo de cédula, exatidão da apuração, e detecção de cédula alterada, removida ou inserida, resultado alterado e trilha adulterada. Resultado em 08/11/2026: todos aprovados. Testes automatizados do próprio autor não substituem auditoria externa.

## 10. Roteiro

1. Parecer jurídico, relatório de impacto e definição do primeiro cliente, começando por votações institucionais.
2. Backend segregado, HSM, duplo fator e identidade contratada.
3. Criptografia em limiar, provas verificáveis e desafio de cédula, com revisão externa.
4. Testes de carga, intrusão e auditoria independente.
5. Piloto real em entidade privada de pequeno porte, com observadores.
6. Candidatura a credenciamento perante a OAB, quando houver edital.

## 11. Link e QR Code de acesso

O gestor gera, em Gestão eleitoral, Painel, um link e um QR Code por eleição já publicada. O link leva somente à eleição e não contém credencial: identidade, aptidão e voto único continuam exigidos. Cada eleição exibe um código de conferência derivado do hash da configuração, para o eleitor comparar com o divulgado pela Comissão. A geração fica na trilha de auditoria.

Em produção: rota pública `/voto/e/{id}` sob HTTPS, domínio único divulgado pela Comissão, alerta contra QR Codes falsos e limite de requisições. O endereço só funciona após a implantação.

## 12. Caminho para a OAB/DF

1. A escolha da modalidade cabe ao Presidente do Conselho Seccional, ad referendum da Diretoria (Prov. 222/2023, art. 25). O DF integrou o piloto on-line autorizado em 2021.
2. Confirmar com a Comissão Eleitoral se haverá credenciamento do Conselho Federal para o próximo pleito e quais requisitos valerão.
3. Conflito de interesses: se pessoa ligada ao BlueJus integrar o Conselho Seccional, tratar previamente impedimento, declaração, abstenção e forma de contratação. Norma específica ainda não conferida.
4. Começar por consulta ou votação institucional sem efeito eleitoral, com observadores e auditoria externa.
5. Só depois apresentar relatório de carga e auditoria para o pleito.

## 13. Comprovante, recibo e boletim impresso

Comprovante de participação (imagem e PDF de impressão): atesta só que o eleitor participou, com nome, inscrição, eleição, data e hora, marca d'água, fita, selo e QR Code. Tem código próprio (BJC) e não contém o código de depósito (BJV), o token nem qualquer dado da cédula.

Horário: o servidor guarda só a faixa horária. A hora exata aparece apenas no comprovante emitido no momento do voto; a 2ª via traz a faixa. Guardar hora exata permitiria correlacionar participação e posição na urna. O IP exigido no Edital 01/2024, item 4.1.9, depende do servidor e deve seguir a mesma separação.

Recibo individual com o voto: não implementado de propósito. O STF, na ADI 5889 (sessão virtual encerrada em 14/9/2020), declarou inconstitucional a impressão do registro de cada voto (art. 59-A da Lei 9.504/1997), por violação à liberdade e ao sigilo do voto. A decisão trata das eleições oficiais, mas o fundamento se aplica ao sigilo exigido em qualquer eleição.

Boletim de urna (PDF em bobina de 80 mm): resultado agregado, sem identificar eleitor, com aptos, comparecimento, faltosos, abertura e fechamento, votos por chapa, brancos, nulos, total, QR Code com os dados e hash. O layout segue boletim real enviado como referência e descrição jornalística. As resoluções do TSE sobre boletim e comprovante não puderam ser lidas (páginas sem conteúdo ou sem acesso). O boletim traz hash, não assinatura digital; assinatura exige chave em HSM no servidor.

## 14. Regras de chapa conferidas

Provimento 222/2023, art. 10: só chapa completa, vedada candidatura isolada; número e denominação próprios, com até 30 caracteres; preferência pela ordem de apresentação. A cédula e as listagens seguem a ordem de registro, sem sorteio (parte lida em resumo: conferir). Subseção pode ter cédula própria (art. 25, § 5º, em resumo: conferir), por isso o sistema admite mais de uma votação na mesma eleição.

## 15. Produto neutro: cenários, pesos, eventos e relatórios

Cenários prontos (assembleia de condomínio, entidade de classe, associação, sindicato, cooperativa, sócios, ensino, consulta, personalizado) definem rótulos, documentos, modalidade, peso, acesso e critério, sempre editáveis.

Voto com peso: o peso vem da lista importada, e cada valor de peso tem chave de autorização própria, para o Autorizador não ver o token e a urna não aceitar peso trocado. Limite: valores de peso usados por menos de 3 eleitores podem permitir identificar o voto ao abrir as cédulas; a tela alerta. Em produção, usar classes agrupadas ou cifra homomórfica.

Condomínio (texto da Lei 14.309/2022 lido em fonte secundária, redação de 2022; conferir versão atual e convenção): Código Civil, art. 1.352, maioria dos presentes com ao menos metade das frações em primeira convocação e votos proporcionais às frações, salvo convenção; art. 1.353, segunda convocação e sessão permanente; art. 1.354-A, assembleia eletrônica se a convenção não vedar, com direitos de voz, debate e voto, instruções no edital e ata após a soma dos votos. Limite: voz e debate exigem videoconferência ou ferramenta própria, e a alteração de voto em sessão permanente (art. 1.353, § 2º) ainda não está implementada. Arts. 1.335, III, e 1.351 não foram conferidos.

Eventos: votações com o mesmo nome de evento têm link único, voto em sequência e relatório conjunto.

Acesso por código: nome, CPF, e-mail conferidos com a lista, código de 6 números válido por 10 minutos, 5 tentativas, foto do documento, selfie e declaração sob o art. 299 do Código Penal (texto lido em LegJur). Dispensa biometria, é mais fraco que a análise prévia, e a revisão é posterior: voto já depositado não pode ser retirado, porque não há vínculo com a identidade. Dependência: serviço de e-mail transacional; o protótipo apenas exibe o código na tela.

Programação: abertura e encerramento automáticos no horário configurado. No protótipo só ocorrem com a página aberta; em produção, por agendador no servidor.

Relação de votantes: nominal, em ordem alfabética, com faixa horária, somente após o encerramento e para perfis autorizados; nunca informa o voto. Tratar a base legal na LGPD e no regulamento de cada entidade, e não usar em votações em que a participação deva ser reservada.

Relatório consolidado: PDF com identificação, responsáveis, critérios, boletins de abertura, eleitorado, encerramento, apuração, relação de votantes, trilha, incidentes e termo com assinaturas.

## 16. Condução pela mesa e sessão permanente

Modos: programado no horário ou conduzido pela mesa. Na condução pela mesa, cada pauta é aberta separadamente, em primeira ou segunda convocação (quórum próprio), por minutos definidos ou sem prazo, com prorrogação e encerramento a qualquer momento.

Sessão permanente (Código Civil, art. 1.353, §§ 1º a 3º, lido em fonte secundária): a mesa registra a data da próxima sessão, até 60 dias, com término em até 90 dias da abertura. Votos já dados ficam registrados; ausentes podem votar na sessão seguinte; quem votou pode alterar o voto até o desfecho, contando somente o último. Na alteração, o aparelho reapresenta o token próprio, sem vínculo com a identidade. Em produção o token fica só no aparelho do eleitor. A ata parcial mostra participação, nunca resultado parcial, para preservar o sigilo até a apuração.

Cenários de assembleia cobertos: primeira e segunda convocação, quórum simples e especial, peso por fração, unidade inadimplente fora da lista, procuração como documento, várias pautas com link único, empate indicado sem decisão automática, votação em minutos, sem prazo e permanente, assembleia híbrida com voto pelo celular dos presentes.

Não cobertos: voz e debate (exigem videoconferência), limite de procurações por pessoa, voto nominal aberto e voto de qualidade.

## 16-A. Entrada de dados, aptidão, equipe e acompanhamento

- Qualidade das fotos: análise local em bj-voto-entrada.js (resolução, escuridão, luz excessiva, foco, corte, distância e reflexo). Limites calibrados com imagens sintéticas: calibrar com fotos reais antes da produção. Em produção a mesma análise deve rodar no servidor, pois a do navegador pode ser contornada. Não confere autenticidade. Corte de rosto na selfie depende de modelo de detecção facial; a regra de contraluz foi descartada por rejeitar pele escura.
- Visibilidade: o filtro do protótipo é feito na tela. Em produção o servidor só pode devolver votações em que o eleitor consta na lista.
- Consulta de situação: nome completo exato. Por padrão exige identificação, para não expor por nome a inaptidão de terceiros; a opção somente por nome é decisão da entidade e demanda base na LGPD e no regulamento. Em produção, limitar consultas por IP.
- Ocorrência e conselho: ocorrência de até 80 caracteres; em registro não apto abre avaliação; a decisão exige justificativa de até 160 caracteres. Horários registrados com segundos e trilha de auditoria. A alteração muda a aptidão depois de a lista ser bloqueada, por isso fica contada em separado do hash original.
- Equipe por votação: perfis com escopo por evento ou votação, cargos livres e permissões fixas; a categoria preenche os cargos. Votação com equipe própria recusa perfis da equipe padrão.
- Localização: colhida na identificação, nunca na cabine, e não vinculada ao voto. Fornecida pelo aparelho, pode ser falsificada. A coleta exige finalidade definida, prazo de guarda e previsão no regulamento.
- Gestor Eleitoral Master: perfil global, somente leitura, com estatísticas agregadas de todas as votações, Painel e Auditoria. Não opera votações e não acessa voto individual.
- Sigilo: configurável como secreto, aberto após o encerramento ou aberto em tempo real, e fixado na publicação. No voto aberto, além da urna cifrada, grava-se registro nominal com data e hora em segundos, conferido com a apuração. Só usar quando a norma da entidade permitir.
- Lista nominal e documento de auditoria: restritos a comissão, auditoria e Gestor Master, com consulta registrada na trilha. Mostram horário da identificação, não do voto; o voto consta por faixa horária. A lista com horários exatos não pode ser publicada: confrontada com a ordem da urna, permitiria aproximar voto e pessoa. Em produção, depositar as cédulas em lotes com atraso aleatório. Presencial ou virtual: definido pelo terminal aberto pela comissão.
- Entrada do votante: tela única (localizar o nome, confirmar a identidade, votar, comprovante). Quem consta como apto na lista vota logo após enviar documento e selfie, e a conferência humana é posterior; o voto depositado não pode ser retirado. Sem lista, permanece a análise prévia.
- Criar eleição: assistente único em 7 etapas (categoria, regras e prazo, acesso, cargos, lista, equipe, revisão), salvo como rascunho a cada avanço. A Comissão cria e pode abrir as vagas da equipe própria; a Administração indica as pessoas.
- Acesso por QR Code: o votante entra do próprio aparelho, sem conta no BlueJus. O protótipo guarda os dados no navegador; para o QR Code funcionar em outro aparelho é indispensável o servidor e o domínio definitivo.
- Ajustes do teste de 09/11/2026: aba Votar do sistema lista todas as votações publicadas (o filtro por lista vale só para quem entra pelo link); link com rota por âncora (#eleicao= e #evento=) na própria página quando hospedada em bluejus.com.br, ou BJVOTO_BASE; número das opções só com dígitos e numeração automática; aviso de alterações não salvas; regra pronta por pauta e resultado em Aprovada ou Rejeitada; quórum de instalação por convocação com presença registrada (link, terminal ou mesa) e abertura abaixo do quórum só por decisão registrada; procurações com instrumento anexo e limite por pessoa; candidato vinculado a unidade; pautas de várias vagas; soma das frações na revisão; WhatsApp individual e lista para envio em lote; minuta de ata em Word com papel timbrado; votação de simulação que pode ser reiniciada e apagada.
- Acompanhar: janela separada com participação e quórum, sem parcial de votos. Em produção, alimentada pelo servidor.
- Comprovante por e-mail: traz votação, nome, identificação, data, hora e código, nunca o voto. O protótipo abre o programa de e-mail; em produção, envio pelo servidor com o anexo.
- Registro em cartório: o relatório traz endereço, QR Code, código divulgado, hash da configuração, cadeia e impressão digital do programa. O endereço só responde após publicação em domínio real. A eficácia do registro deve ser confirmada com o cartório.

## 17. Fontes consultadas

- Provimento 222/2023: https://www.oab.org.br/leisnormas/legislacao/provimentos/222-2023
- Provimento 208/2021: https://www.oab.org.br/leisnormas/legislacao/provimentos/208-2021
- Provimento 146/2011 (revogado): https://www.oab.org.br/leisnormas/legislacao/provimentos/146-2011
- Edital de Credenciamento 01/2024-CFOAB: https://s.oab.org.br/PDF/Edital%20de%20Credenciamento.pdf
- Autorização on-line em 2021: https://www.oab.org.br/noticia/59083/pleno-aprova-autorizacao-para-votacao-virtual-em-cinco-seccionais
- Regulamento Geral, cap. VII (cópia de 2018): https://eleicoes.oabpr.org.br/wp-content/uploads/2018/10/regulamento-geral-capi%CC%81tulo-vii-eleicoes.pdf
- Lei 14.309/2022 comentada: https://www.dizerodireito.com.br/2022/03/lei-143092022-permite-realizacao-de.html
- Art. 299 do Código Penal: https://www.legjur.com/legislacao/art/dcl_00028481940-299
- STF, ADI 5889: https://portal.stf.jus.br/noticias/verNoticiaDetalhe.asp?idConteudo=451785
- Resolução TSE 23.728/2024: https://www.tse.jus.br/legislacao/compilada/res/2024/resolucao-no-23-728-de-27-de-fevereiro-de-2024
