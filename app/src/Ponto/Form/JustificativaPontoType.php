<?php

namespace App\Ponto\Form;

use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Validacao\RestricoesAnexoJustificativa;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class JustificativaPontoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('datas', HiddenType::class, [
                'mapped'      => false,
                'constraints' => [
                    new NotBlank(message: 'Selecione ao menos uma data.'),
                ],
            ])
            ->add('tipo', ChoiceType::class, [
                'label'       => 'Tipo de Justificativa',
                'mapped'      => false,
                'choices'     => TipoJustificativa::asGroupedChoices(),
                'placeholder' => 'Selecione o tipo',
                'constraints' => [
                    new NotBlank(message: 'Selecione o tipo de justificativa.'),
                ],
            ])
            ->add('abonoParcial', CheckboxType::class, [
                'label'    => 'Abono parcial (saída para consulta e retorno no mesmo dia)',
                'mapped'   => false,
                'required' => false,
            ])
            ->add('horaInicioAbono', TimeType::class, [
                'label'    => 'Saída para atestado',
                'mapped'   => false,
                'required' => false,
                'widget'   => 'single_text',
            ])
            ->add('horaFimAbono', TimeType::class, [
                'label'    => 'Retorno do atestado',
                'mapped'   => false,
                'required' => false,
                'widget'   => 'single_text',
            ])
            ->add('tipoRegistroEsquecido', ChoiceType::class, [
                'mapped'      => false,
                'required'    => false,
                'choices'     => [
                    'Entrada' => 'entrada',
                    'Repouso' => 'repouso',
                    'Retorno' => 'retorno',
                    'Saída'   => 'saida',
                ],
                'placeholder' => 'Selecione o tipo de batida',
            ])
            ->add('horaRegistroEsquecido', TimeType::class, [
                'mapped'   => false,
                'required' => false,
                'widget'   => 'single_text',
            ])
            ->add('anexo', FileType::class, [
                'label'       => 'Atestado / Comprovante',
                'mapped'      => false,
                'required'    => false,
                // A regra mora em RestricoesAnexoJustificativa desde a E1: a edição aplica
                // exatamente a mesma, e antes não aplicava nenhuma.
                'constraints' => [RestricoesAnexoJustificativa::constraint()],
            ])
;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => null,
            'csrf_token_id'  => 'justificativa_ponto',
        ]);
    }
}
