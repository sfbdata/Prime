<?php

declare(strict_types=1);

namespace App\Ponto\Enum;

enum TipoJustificativa: string
{
    // --- 1. Legais (CLT / Legislação) ---
    case AtestadoMedico          = 'atestado_medico';
    case AcompanhamentoMedico    = 'acompanhamento';        // slug legado — manter
    case LicencaMaternidade      = 'licenca_maternidade';
    case LicencaPaternidade      = 'licenca_paternidade';   // slug legado — manter
    case DoacaoSangue            = 'doacao_sangue';         // slug legado — manter
    case AlistamentoEleitoral    = 'alistamento_eleitoral';
    case ComparecimentoJuizo     = 'comparecimento_juizo';  // slug legado — manter
    case ServicoMilitar          = 'servico_militar';
    case FalecimentoFamiliar     = 'falecimento_familiar';  // slug legado — manter
    case LicencaGala             = 'licenca_gala';          // slug legado — manter
    case AcidenteTrabalho        = 'acidente_trabalho';
    case LicencaMedicaInss       = 'licenca_medica_inss';

    // --- 2. Operacionais ---
    case ReuniaoExterna              = 'reuniao_externa';
    case Audiencia                   = 'audiencia';
    case AtendimentoExterno          = 'atendimento_externo';
    case TrabalhoExterno             = 'trabalho_externo';
    case DeslocamentoServico         = 'deslocamento_servico';
    case TreinamentoCapacitacao      = 'treinamento_capacitacao';
    case AjusteJornada               = 'ajuste_jornada';
    case CompensacaoHoras            = 'compensacao_horas';
    case HoraExtraAutorizada         = 'hora_extra_autorizada';
    case SaidaAntecipadaAutorizada   = 'saida_antecipada_autorizada';
    case EntradaTardiaAutorizada     = 'entrada_tardia_autorizada';

    // --- 3. Intercorrências ---
    case AtrasoJustificado        = 'atraso_justificado';
    case FaltaJustificada         = 'falta_justificada';
    case ProblemaTransporte       = 'problema_transporte';
    case MotivoPessoal            = 'motivo_pessoal';
    case EmergenciaFamiliar       = 'emergencia_familiar';
    case ProblemaSaudeSemAtestado = 'problema_saude_sem_atestado';

    // --- 4. Técnicas do Sistema ---
    case EsquecimentoRegistro    = 'esquecimento_registro';
    case RegistroIncorreto       = 'registro_incorreto';
    case AjusteManualAutorizado  = 'ajuste_manual_autorizado';
    case FalhaSistema            = 'falha_sistema';
    case CorrecaoPonto           = 'correcao_ponto';

    // --- 5. Situações Críticas ---
    case FaltaNaoJustificada    = 'falta_nao_justificada';  // slug legado — manter
    case AbandonoPosto          = 'abandono_posto';
    case SaidaSemAutorizacao    = 'saida_sem_autorizacao';
    case DescumprimentoJornada  = 'descumprimento_jornada';

    // --- 6. Feriados e Regimes Especiais ---
    case FeriadoTrabalhado   = 'feriado_trabalhado';
    case CompensacaoFeriado  = 'compensacao_feriado';
    case Plantao             = 'plantao';
    case Sobreaviso          = 'sobreaviso';
    case FolgaAniversario    = 'folga_aniversario';

    public function label(): string
    {
        return match ($this) {
            self::AtestadoMedico          => 'Atestado Médico',
            self::AcompanhamentoMedico    => 'Acompanhamento Médico (filho, cônjuge, dependente)',
            self::LicencaMaternidade      => 'Licença Maternidade',
            self::LicencaPaternidade      => 'Licença Paternidade',
            self::DoacaoSangue            => 'Doação de Sangue',
            self::AlistamentoEleitoral    => 'Alistamento Eleitoral',
            self::ComparecimentoJuizo     => 'Comparecimento em Juízo',
            self::ServicoMilitar          => 'Serviço Militar Obrigatório',
            self::FalecimentoFamiliar     => 'Falecimento de Familiar (Licença Nojo)',
            self::LicencaGala             => 'Casamento (Licença Gala)',
            self::AcidenteTrabalho        => 'Acidente de Trabalho',
            self::LicencaMedicaInss       => 'Licença Médica (INSS)',

            self::ReuniaoExterna              => 'Reunião Externa',
            self::Audiencia                   => 'Audiência',
            self::AtendimentoExterno          => 'Atendimento Externo / Visita Técnica',
            self::TrabalhoExterno             => 'Trabalho Externo (Home Office / Campo)',
            self::DeslocamentoServico         => 'Deslocamento a Serviço',
            self::TreinamentoCapacitacao      => 'Treinamento / Capacitação',
            self::AjusteJornada               => 'Ajuste de Jornada Autorizado',
            self::CompensacaoHoras            => 'Compensação de Horas (Banco de Horas)',
            self::HoraExtraAutorizada         => 'Hora Extra Autorizada',
            self::SaidaAntecipadaAutorizada   => 'Saída Antecipada Autorizada',
            self::EntradaTardiaAutorizada     => 'Entrada Tardia Autorizada',

            self::AtrasoJustificado        => 'Atraso Justificado',
            self::FaltaJustificada         => 'Falta Justificada',
            self::ProblemaTransporte       => 'Problema de Transporte',
            self::MotivoPessoal            => 'Motivo Pessoal (com aprovação)',
            self::EmergenciaFamiliar       => 'Emergência Familiar',
            self::ProblemaSaudeSemAtestado => 'Problema de Saúde sem Atestado',

            self::EsquecimentoRegistro    => 'Esquecimento de Registro',
            self::RegistroIncorreto       => 'Registro Incorreto',
            self::AjusteManualAutorizado  => 'Ajuste Manual Autorizado',
            self::FalhaSistema            => 'Falha no Sistema',
            self::CorrecaoPonto           => 'Correção de Ponto',

            self::FaltaNaoJustificada    => 'Falta não Justificada',
            self::AbandonoPosto          => 'Abandono de Posto',
            self::SaidaSemAutorizacao    => 'Saída sem Autorização',
            self::DescumprimentoJornada  => 'Descumprimento de Jornada',

            self::FeriadoTrabalhado   => 'Feriado Trabalhado',
            self::CompensacaoFeriado  => 'Compensação de Feriado',
            self::Plantao             => 'Plantão',
            self::Sobreaviso          => 'Sobreaviso',
            self::FolgaAniversario    => 'Folga Aniversário',
        };
    }

    public function categoria(): CategoriaJustificativa
    {
        return match ($this) {
            self::AtestadoMedico,
            self::AcompanhamentoMedico,
            self::LicencaMaternidade,
            self::LicencaPaternidade,
            self::DoacaoSangue,
            self::AlistamentoEleitoral,
            self::ComparecimentoJuizo,
            self::ServicoMilitar,
            self::FalecimentoFamiliar,
            self::LicencaGala,
            self::AcidenteTrabalho,
            self::LicencaMedicaInss       => CategoriaJustificativa::LegalClt,

            self::ReuniaoExterna,
            self::Audiencia,
            self::AtendimentoExterno,
            self::TrabalhoExterno,
            self::DeslocamentoServico,
            self::TreinamentoCapacitacao,
            self::AjusteJornada,
            self::CompensacaoHoras,
            self::HoraExtraAutorizada,
            self::SaidaAntecipadaAutorizada,
            self::EntradaTardiaAutorizada => CategoriaJustificativa::Operacional,

            self::AtrasoJustificado,
            self::FaltaJustificada,
            self::ProblemaTransporte,
            self::MotivoPessoal,
            self::EmergenciaFamiliar,
            self::ProblemaSaudeSemAtestado => CategoriaJustificativa::Intercorrencia,

            self::EsquecimentoRegistro,
            self::RegistroIncorreto,
            self::AjusteManualAutorizado,
            self::FalhaSistema,
            self::CorrecaoPonto            => CategoriaJustificativa::Tecnica,

            self::FaltaNaoJustificada,
            self::AbandonoPosto,
            self::SaidaSemAutorizacao,
            self::DescumprimentoJornada   => CategoriaJustificativa::Critica,

            self::FeriadoTrabalhado,
            self::CompensacaoFeriado,
            self::Plantao,
            self::Sobreaviso,
            self::FolgaAniversario        => CategoriaJustificativa::RegimeEspecial,
        };
    }

    /**
     * Retorna array plano ['Label' => 'slug'] para uso em selects não agrupados.
     *
     * @return array<string, string>
     */
    public static function asPlanarChoices(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $result[$case->label()] = $case->value;
        }

        return $result;
    }

    /**
     * Retorna array agrupado por categoria para uso em ChoiceType do Symfony.
     *
     * @return array<string, array<string, string>>
     */
    public static function asGroupedChoices(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $catLabel = $case->categoria()->label();
            $result[$catLabel][$case->label()] = $case->value;
        }

        return $result;
    }

    /**
     * Retorna todos os slugs válidos para validação.
     *
     * @return string[]
     */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
