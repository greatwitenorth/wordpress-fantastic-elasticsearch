<?php
namespace elasticsearch;

class Searcher{
	public function query($search, $pageIndex, $size, $facets = array()){
		$shoulds = array();
		$musts = array();
		$filters = array();
		$bytype = null;

		foreach(Api::types() as $type){
			if($type == $search){
				$bytype = $search;
				$search = null;
			}
		}

		foreach(Api::taxonomies() as $tax){
			if($search){
				$score = Api::score('tax', $tax);

				if($score > 0){
					$shoulds[] = array('text' => array( $tax => array(
						'query' => $search,
						'boost' => $score
					)));
				}
			}

			if(is_array($facets[$tax])){
				foreach($facets[$tax] as $operation => $facet){
					if(is_string($operation)){
						if($operation == 'and'){
							if(is_array($facet)){
								foreach($facet as $value){
									$musts[] = array( 'term' => array( $tax => $value ));
								}
							}else{
								$musts[] = array( 'term' => array( $tax => $facet ));
							}
						}

						if($operation == 'or'){
							if(is_array($facet)){
								foreach($facet as $value){
									$filters[] = array( 'term' => array( $tax => $value ));
								}
							}else{
								$filters[] = array( 'term' => array( $tax => $facet ));
							}
						}
					}else{
						$musts[] = array( 'term' => array( $tax => $facet ));
					}
				}
			}elseif($facets[$tax]){
				$musts[] = array( 'term' => array( $tax => $facets[$tax] ));
			}
		}

		if($search){
			foreach(Api::fields() as $field){
				$score = Api::score('field', $field);

				if($field == 'post_date'){
					$shoulds[] = array('range' => array($field => array(
							'boost' => 15,
							'gte' => date('Y-m-d H:m:s')
					)));

					$shoulds[] = array('range' => array($field => array(
							'boost' => 5,
							'lte' => date('Y-m-d H:m:s', strtotime("-7 days"))
					)));
				}else if($score > 0){
					$shoulds[] = array('text' => array($field => array(
						'query' => $search,
						'boost' => $score
					)));
				}
			}
		}

		$args = array();

		if(count($shoulds) > 0){
			$args['query']['custom_filters_score']['query']['bool']['should'] = $shoulds;
		}

		if(count($filters) > 0){
			$args['filter']['bool']['should'] = $filters;
		}

		$date_score = Api::score('field', 'post_date') * 0.05;
		$args['query']['custom_filters_score']['filters'][0]['filter'] =  array('exists' => array('field' => 'post_date'));
		$args['query']['custom_filters_score']['filters'][0]['script'] = "($date_score / ((3.16*pow(10,-11)) * abs(now - doc[\"post_date\"].date.getMillis()) + 0.05)) + 1.0";
		$args['query']['custom_filters_score']['params'] = array( 'now' => time()*1000);

		if(count($musts) > 0){
			$args['query']['custom_filters_score']['query']['bool']['must'] = $musts;
		}

		foreach(Api::facets() as $facet){
			$args['facets'][$facet]['terms']['field'] = $facet;

			if(count($filters) > 0){
				foreach($filters as $filter){
					if(!$filter['term'][$facet]){
						$args['facets'][$facet]['facet_filter']['bool']['should'][] = $filter;
					}
				}
			}
		}

		$query =new \Elastica_Query($args);
		$query->setFrom($pageIndex * $size);
		$query->setSize($size);
		$query->setFields(array('id'));

		//Possibility to modify the query after it was built
		\apply_filters('elastica_query', $query);

		try{
			$index = Api::index(false);

			$search = new \Elastica_Search($index->getClient());
			$search->addIndex($index);

			if($bytype){
				$search->addType($index->getType($bytype));
			}

			\apply_filters( 'elastica_pre_search', $search );

			$response = $search->search($query);

		}catch(\Exception $ex){
			return null;
		}

		$val = array(
			'total' => $response->getTotalHits(),
			'scores' => array(),
			'facets' => array()
		);

		foreach($response->getFacets() as $name => $facet){
			foreach($facet['terms'] as $term){
				$val['facets'][$name][$term['term']] = $term['count'];
			}
		}

		foreach($response->getResults() as $result){
			$val['scores'][$result->getId()] = $result->getScore();
		}

		$val['ids'] = array_keys($val['scores']);

		//Possibility to alter the results
		return \apply_filters('elastica_results', $val, $response);
	}
}
?>
