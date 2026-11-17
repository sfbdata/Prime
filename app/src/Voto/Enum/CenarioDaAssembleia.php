<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Cenário (categoria) da assembleia, como o assistente do desenho 18 (bj-voto-cenarios.js): o tipo de entidade que
 * promove a votação. Define só os RÓTULOS: o da entidade, as duas linhas de assinatura dos documentos (a1 e a2), o
 * aviso do passo 1, o ícone e o peso inicial sugerido. Não muda regra de apuração nem a cédula, por isso fica fora do
 * hash da configuração publicada. Padrão: condomínio (o comportamento de antes).
 */
enum CenarioDaAssembleia: string
{
    case Condominio = 'condominio';
    case Classe = 'classe';
    case Associacao = 'associacao';
    case Sindicato = 'sindicato';
    case Cooperativa = 'cooperativa';
    case Sociedade = 'sociedade';
    case Ensino = 'ensino';
    case Consulta = 'consulta';
    case Livre = 'livre';

    public function rotulo(): string
    {
        return match ($this) {
            self::Condominio => 'Assembleia de condomínio',
            self::Classe => 'Entidade de classe ou conselho',
            self::Associacao => 'Associação ou clube',
            self::Sindicato => 'Sindicato',
            self::Cooperativa => 'Cooperativa',
            self::Sociedade => 'Sócios ou acionistas',
            self::Ensino => 'Instituição de ensino',
            self::Consulta => 'Consulta interna',
            self::Livre => 'Personalizado',
        };
    }

    /** Rótulo do campo do nome da entidade (desenho: `rot.ent`). */
    public function entidade(): string
    {
        return match ($this) {
            self::Condominio => 'Condomínio',
            self::Associacao => 'Associação',
            self::Sindicato => 'Sindicato',
            self::Cooperativa => 'Cooperativa',
            self::Sociedade => 'Sociedade',
            self::Ensino => 'Instituição',
            self::Consulta => 'Organização',
            self::Classe, self::Livre => 'Entidade',
        };
    }

    /** Primeira linha de assinatura dos documentos (desenho: `rot.a1`). */
    public function assinatura1(): string
    {
        return match ($this) {
            self::Classe, self::Sindicato => 'Presidência da comissão eleitoral',
            self::Sociedade => 'Presidência da reunião',
            self::Ensino, self::Livre => 'Presidência da comissão',
            self::Consulta => 'Responsável pela consulta',
            self::Condominio, self::Associacao, self::Cooperativa => 'Presidência da assembleia',
        };
    }

    /** Segunda linha de assinatura dos documentos (desenho: `rot.a2`). */
    public function assinatura2(): string
    {
        return match ($this) {
            self::Classe, self::Sindicato => 'Membro da comissão eleitoral',
            self::Sociedade => 'Secretaria da reunião',
            self::Ensino, self::Livre => 'Membro da comissão',
            self::Consulta => 'Auditoria',
            self::Condominio, self::Associacao, self::Cooperativa => 'Secretaria da assembleia',
        };
    }

    /** Aviso do passo 1 (desenho: `ajuda`). */
    public function ajuda(): string
    {
        return match ($this) {
            self::Condominio => 'Votos por fração ideal, salvo disposição diversa da convenção. Confira na convenção o quórum de cada pauta, a segunda convocação e quem está apto a votar.',
            self::Classe => 'Siga o regulamento eleitoral da entidade: registro de chapas, ordem de apresentação e lista de aptos.',
            self::Associacao => 'Confira no estatuto o quórum e a forma de votação.',
            self::Sindicato => 'Confira no estatuto as regras de chapa e de quórum.',
            self::Cooperativa => 'Confira no estatuto os quóruns e a representação.',
            self::Sociedade => 'Peso conforme quotas ou ações. Confira contrato social ou estatuto.',
            self::Ensino => 'Grêmios, centros acadêmicos e colegiados.',
            self::Consulta => 'Pesquisas e decisões internas.',
            self::Livre => 'Configure livremente.',
        };
    }

    /** Rótulo da identificação da unidade ou do eleitor na lista de aptos (desenho: `rot.id`). */
    public function identificacao(): string
    {
        return match ($this) {
            self::Condominio => 'Unidade',
            self::Classe => 'Número de inscrição',
            self::Associacao => 'CPF',
            self::Sindicato => 'Matrícula sindical',
            self::Cooperativa => 'Matrícula de cooperado',
            self::Sociedade => 'Identificação do sócio',
            self::Ensino, self::Consulta => 'Matrícula',
            self::Livre => 'Identificação',
        };
    }

    /**
     * O cenário prevê a relação nominal de votantes (desenho: `lista` de bj-voto-cenarios.js): condomínio, associação e
     * sociedade. Nos demais a ata não traz a relação (spec voto-equipe-e-master-1611, seção 2).
     */
    public function temListaDeVotantes(): bool
    {
        return \in_array($this, [self::Condominio, self::Associacao, self::Sociedade], true);
    }

    /** Rótulo do agrupamento da lista de aptos (desenho: `rot.gr`). */
    public function grupo(): string
    {
        return match ($this) {
            self::Condominio => 'Bloco ou torre',
            self::Classe => 'Regional',
            self::Associacao => 'Categoria',
            self::Sindicato => 'Base ou categoria',
            self::Cooperativa => 'Núcleo',
            self::Sociedade => 'Classe',
            self::Ensino => 'Curso',
            self::Consulta => 'Setor',
            self::Livre => 'Grupo',
        };
    }

    /**
     * Modalidade da votação (desenho: `modalidade` do cenário e `MODR`), a partir do tipo da pauta: chapa nos cenários de
     * chapa (classe e sindicato) e eleição individual nos demais; pauta de assembleia, ou consulta no cenário de consulta.
     * Não grava nada: é só o texto "entidade · modalidade" da cédula e da aba Votar.
     */
    public function modalidade(TipoDaPauta $tipo): string
    {
        if ($tipo->eleitoral()) {
            return \in_array($this, [self::Classe, self::Sindicato], true) ? 'chapa' : 'individual';
        }

        return self::Consulta === $this ? 'consulta' : 'pauta';
    }

    /**
     * Os cargos da equipe própria da categoria (desenho: `EQ` de bj-voto-cenarios.js, C16-27), na ordem do desenho:
     * comissão, conselho, auditoria e fiscal. Ficam de fora a Validação e o Guardião (dependem de C1 e C4). "Aplicar
     * cargos da categoria" cria uma linha "A designar" para cada um.
     *
     * @return list<array{papel: PapelNaEquipe, cargo: string}>
     */
    public function cargosDaEquipe(): array
    {
        [$comissao, $conselho, $auditoria, $fiscal] = match ($this) {
            self::Condominio => ['Presidência da assembleia', 'Conselho consultivo', 'Conselho fiscal', 'Fiscal da votação'],
            self::Classe => ['Comissão Eleitoral', 'Conselho Eleitoral', 'Auditoria', 'Fiscal de chapa'],
            self::Associacao => ['Presidência da assembleia', 'Conselho deliberativo', 'Conselho fiscal', 'Fiscal da votação'],
            self::Sindicato => ['Comissão Eleitoral', 'Conselho Eleitoral', 'Conselho fiscal', 'Fiscal de chapa'],
            self::Cooperativa => ['Presidência da assembleia', 'Conselho de administração', 'Conselho fiscal', 'Fiscal da votação'],
            self::Sociedade => ['Presidência da reunião', 'Conselho de sócios', 'Auditoria', 'Fiscal da votação'],
            self::Ensino => ['Comissão Eleitoral', 'Colegiado', 'Auditoria', 'Fiscal de chapa'],
            self::Consulta => ['Responsável pela consulta', 'Comitê de avaliação', 'Auditoria', 'Fiscal'],
            self::Livre => ['Comissão Eleitoral', 'Conselho Eleitoral', 'Auditoria', 'Fiscal'],
        };

        return [
            ['papel' => PapelNaEquipe::Comissao, 'cargo' => $comissao],
            ['papel' => PapelNaEquipe::Conselho, 'cargo' => $conselho],
            ['papel' => PapelNaEquipe::Auditoria, 'cargo' => $auditoria],
            ['papel' => PapelNaEquipe::Fiscal, 'cargo' => $fiscal],
        ];
    }

    /** Ícone Bootstrap Icons do botão da categoria (mapa `edCens` do desenho). */
    public function icone(): string
    {
        return match ($this) {
            self::Condominio => 'bi-building',
            self::Classe => 'bi-award',
            self::Associacao => 'bi-people',
            self::Sindicato => 'bi-megaphone',
            self::Cooperativa => 'bi-diagram-3',
            self::Sociedade => 'bi-briefcase',
            self::Ensino => 'bi-mortarboard',
            self::Consulta => 'bi-chat-square-text',
            self::Livre => 'bi-sliders',
        };
    }

    /** Peso inicial sugerido (desenho: `ponderacao`: "peso" vira fração ideal, "um" vira uma unidade, um voto). */
    public function pesoSugerido(): PesoDoVoto
    {
        return match ($this) {
            self::Condominio, self::Sociedade => PesoDoVoto::Fracao,
            default => PesoDoVoto::Unidade,
        };
    }
}
