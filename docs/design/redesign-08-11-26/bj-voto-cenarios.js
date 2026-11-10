/* BlueJus · Voto: cenários prontos. Definem rótulos, documentos e regras iniciais; tudo pode ser ajustado em cada votação. */
(function () {
  const D = (f, v, c, s) => [{ k: 'cf', r: f, ic: 'bi-person-vcard', cap: 'environment' }, { k: 'cv', r: v, ic: 'bi-credit-card-2-back', cap: 'environment' }, { k: 'doc', r: c, ic: 'bi-card-heading', cap: 'environment' }, { k: 'sel', r: s || 'Selfie', ic: 'bi-person-bounding-box', cap: 'user' }];
  const SN = [{ num: '1', nome: 'Sim', desc: '', prop: '', foto: '', comp: '' }, { num: '2', nome: 'Não', desc: '', prop: '', foto: '', comp: '' }];
  const L = [
    { id: 'condominio', r: 'Assembleia de condomínio', modalidade: 'pauta', ponderacao: 'peso', multi: true, lista: true, modoFim: 'mesa', alterar: true, limSessao: { prox: 60, total: 90 }, rot: { ent: 'Condomínio', id: 'Unidade', gr: 'Bloco ou torre', a1: 'Presidência da assembleia', a2: 'Secretaria da assembleia', pauta: true }, docs: D('Documento (frente)', 'Documento (verso)', 'Titularidade ou procuração'), cargo: { nome: '', dig: 1, regra: 'mais', base: 'validos', quorum: 50, quorum2: 0, cands: SN }, ajuda: 'Votos por fração ideal, salvo disposição diversa da convenção. Confira na convenção o quórum de cada pauta, a segunda convocação e quem está apto a votar.' },
    { id: 'classe', r: 'Entidade de classe ou conselho', modalidade: 'chapa', ponderacao: 'um', rot: { ent: 'Entidade', id: 'Número de inscrição', gr: 'Regional', a1: 'Presidência da comissão eleitoral', a2: 'Membro da comissão eleitoral' }, docs: D('Carteira profissional (frente)', 'Carteira profissional (verso)', 'Documento de identidade'), cargo: { nome: '', dig: 2, regra: 'mais', base: 'validos', quorum: 0, cands: [] }, ajuda: 'Siga o regulamento eleitoral da entidade: registro de chapas, ordem de apresentação e lista de aptos.' },
    { id: 'associacao', r: 'Associação ou clube', modalidade: 'individual', ponderacao: 'um', acesso: 'token', lista: true, rot: { ent: 'Associação', id: 'CPF', gr: 'Categoria', a1: 'Presidência da assembleia', a2: 'Secretaria da assembleia' }, docs: D('Documento (frente)', 'Documento (verso)', 'Comprovante de associação'), cargo: { nome: '', dig: 2, regra: 'mais', base: 'validos', quorum: 0, cands: [] }, ajuda: 'Confira no estatuto o quórum e a forma de votação.' },
    { id: 'sindicato', r: 'Sindicato', modalidade: 'chapa', ponderacao: 'um', rot: { ent: 'Sindicato', id: 'Matrícula sindical', gr: 'Base ou categoria', a1: 'Presidência da comissão eleitoral', a2: 'Membro da comissão eleitoral' }, docs: D('Documento (frente)', 'Documento (verso)', 'Comprovante de filiação'), cargo: { nome: '', dig: 2, regra: 'mais', base: 'validos', quorum: 0, cands: [] }, ajuda: 'Confira no estatuto as regras de chapa e de quórum.' },
    { id: 'cooperativa', r: 'Cooperativa', modalidade: 'pauta', ponderacao: 'um', rot: { ent: 'Cooperativa', id: 'Matrícula de cooperado', gr: 'Núcleo', a1: 'Presidência da assembleia', a2: 'Secretaria da assembleia', pauta: true }, docs: D('Documento (frente)', 'Documento (verso)', 'Comprovante de cooperado'), cargo: { nome: '', dig: 1, regra: 'mais', base: 'validos', quorum: 0, cands: SN }, ajuda: 'Confira no estatuto os quóruns e a representação.' },
    { id: 'sociedade', r: 'Sócios ou acionistas', modalidade: 'pauta', ponderacao: 'peso', multi: true, lista: true, rot: { ent: 'Sociedade', id: 'Identificação do sócio', gr: 'Classe', a1: 'Presidência da reunião', a2: 'Secretaria da reunião', pauta: true }, docs: D('Documento (frente)', 'Documento (verso)', 'Comprovante de participação societária'), cargo: { nome: '', dig: 1, regra: 'absoluta', base: 'aptos', quorum: 0, cands: SN }, ajuda: 'Peso conforme quotas ou ações. Confira contrato social ou estatuto.' },
    { id: 'ensino', r: 'Instituição de ensino', modalidade: 'individual', ponderacao: 'um', rot: { ent: 'Instituição', id: 'Matrícula', gr: 'Curso', a1: 'Presidência da comissão', a2: 'Membro da comissão' }, docs: D('Documento (frente)', 'Documento (verso)', 'Comprovante de matrícula'), cargo: { nome: '', dig: 2, regra: 'mais', base: 'validos', quorum: 0, cands: [] }, ajuda: 'Grêmios, centros acadêmicos e colegiados.' },
    { id: 'consulta', r: 'Consulta interna', modalidade: 'consulta', ponderacao: 'um', rot: { ent: 'Organização', id: 'Matrícula', gr: 'Setor', a1: 'Responsável pela consulta', a2: 'Auditoria', pauta: true }, docs: D('Documento (frente)', 'Documento (verso)', 'Comprovante de vínculo'), cargo: { nome: '', dig: 1, regra: 'mais', base: 'validos', quorum: 0, cands: SN }, ajuda: 'Pesquisas e decisões internas.' },
    { id: 'livre', r: 'Personalizado', modalidade: 'individual', ponderacao: 'um', rot: { ent: 'Entidade', id: 'Identificação', gr: 'Grupo', a1: 'Presidência da comissão', a2: 'Membro da comissão' }, docs: D('Documento (frente)', 'Documento (verso)', 'Comprovante de vínculo'), cargo: { nome: '', dig: 2, regra: 'mais', base: 'validos', quorum: 0, cands: [] }, ajuda: 'Configure livremente.' }
  ];
  const E = (a, b, c, g, d, f) => [[a, 'comissao'], [b, 'validador'], [c, 'conselho']].concat([1, 2, 3, 4, 5].map(i => [g + ' ' + i, 'guardiao'])).concat([[d, 'auditor'], [f, 'fiscal']]);
  const EQ = {
    condominio: E('Presidência da assembleia', 'Secretaria da assembleia', 'Conselho consultivo', 'Escrutinador', 'Conselho fiscal', 'Fiscal da votação'),
    classe: E('Comissão Eleitoral', 'Validação de inscritos', 'Conselho Eleitoral', 'Guardião', 'Auditoria', 'Fiscal de chapa'),
    associacao: E('Presidência da assembleia', 'Secretaria da assembleia', 'Conselho deliberativo', 'Escrutinador', 'Conselho fiscal', 'Fiscal da votação'),
    sindicato: E('Comissão Eleitoral', 'Validação de filiados', 'Conselho Eleitoral', 'Guardião', 'Conselho fiscal', 'Fiscal de chapa'),
    cooperativa: E('Presidência da assembleia', 'Secretaria da assembleia', 'Conselho de administração', 'Escrutinador', 'Conselho fiscal', 'Fiscal da votação'),
    sociedade: E('Presidência da reunião', 'Secretaria da reunião', 'Conselho de sócios', 'Escrutinador', 'Auditoria', 'Fiscal da votação'),
    ensino: E('Comissão Eleitoral', 'Secretaria acadêmica', 'Colegiado', 'Guardião', 'Auditoria', 'Fiscal de chapa'),
    consulta: E('Responsável pela consulta', 'Validação', 'Comitê de avaliação', 'Guardião', 'Auditoria', 'Fiscal'),
    livre: E('Comissão Eleitoral', 'Validação', 'Conselho Eleitoral', 'Guardião', 'Auditoria', 'Fiscal')
  };
  const CG = {
    condominio: ['Síndico(a)', 'Subsíndico(a)', 'Conselho fiscal', 'Aprovação de contas', 'Previsão orçamentária', 'Obra ou benfeitoria', 'Alteração da convenção'],
    classe: ['Diretoria', 'Conselho', 'Delegados', 'Caixa de assistência'],
    associacao: ['Presidência', 'Vice-presidência', 'Diretoria', 'Conselho fiscal', 'Conselho deliberativo'],
    sindicato: ['Diretoria executiva', 'Conselho fiscal', 'Delegados sindicais'],
    cooperativa: ['Conselho de administração', 'Conselho fiscal', 'Destinação das sobras', 'Prestação de contas'],
    sociedade: ['Aprovação das contas', 'Destinação dos lucros', 'Eleição de administradores', 'Alteração do contrato social'],
    ensino: ['Reitoria ou direção', 'Vice-direção', 'Coordenação de curso', 'Representação estudantil'],
    consulta: ['Pergunta da consulta'],
    livre: []
  };
  L.forEach(c => { c.equipe = EQ[c.id] || EQ.livre; c.cargos = CG[c.id] || []; if (c.id === 'classe') c.identTipo = 'numero'; if (c.id === 'condominio') { c.inst1 = 50; c.inst2 = 0; } });
  window.BJVotoCenarios = { lista: L, por: id => L.find(x => x.id === id) || L[L.length - 1] };
})();
