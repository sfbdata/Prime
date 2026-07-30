<?php

declare(strict_types=1);

namespace App\Ponto\Form;

use App\Ponto\DTO\LancamentoHorasPagasInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LancamentoHorasPagasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mes', ChoiceType::class, [
                'label'   => 'Competência (mês)',
                'choices' => [
                    'Janeiro' => 1, 'Fevereiro' => 2, 'Março' => 3, 'Abril' => 4,
                    'Maio' => 5, 'Junho' => 6, 'Julho' => 7, 'Agosto' => 8,
                    'Setembro' => 9, 'Outubro' => 10, 'Novembro' => 11, 'Dezembro' => 12,
                ],
            ])
            ->add('ano', IntegerType::class, [
                'label'      => 'Ano',
                // Campo vazio vira 0 (competência inválida, recusada na validação) em vez de null,
                // que estouraria ao ser escrito na propriedade tipada `int` do DTO.
                'empty_data' => '0',
            ])
            ->add('operacao', ChoiceType::class, [
                'label'    => 'Operação',
                'expanded' => true,
                'choices'  => [
                    'Descontar do banco'   => LancamentoHorasPagasInput::OPERACAO_DESCONTAR,
                    'Acrescentar ao banco' => LancamentoHorasPagasInput::OPERACAO_ACRESCENTAR,
                ],
            ])
            ->add('horas', IntegerType::class, [
                'label'      => 'Horas',
                'empty_data' => '0',
            ])
            ->add('minutos', IntegerType::class, [
                'label'      => 'Minutos',
                'empty_data' => '0',
            ])
            ->add('motivo', TextareaType::class, [
                'label'      => 'Motivo',
                'empty_data' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LancamentoHorasPagasInput::class,
            // CSRF é validado explicitamente no controller, por intenção
            // ('horas_pagas_lancar_<userId>' etc.), como em RegistroPontoManualType — evita
            // mecanismo duplo de token.
            'csrf_protection' => false,
        ]);
    }
}
