<?php
/**
 * @package Anta_Distiller
 */
/**
 * index stemming
 */
class Anta_Distiller_Rws_Alchemy extends Anta_Distiller_ThreadHandler{
	
	public function init(){
		
		$document =& $this->_target;
		$user     =& $this->_distiller->user;
		$config   = new Zend_Config_Ini(  APPLICATION_PATH . "/configs/application.ini", "services" );
		
		// 1. load alchemy configuration
		$this->_log( "curl Alchemy: ". $config->alchemy->api->entities.", limit:".$config->alchemy->api->dailyLimit, false );
		
		// 1.b check alchemy daily limit with config service
		$dailyRequest = Application_Model_QuotasMapper::getDailyRequest();
		$this->_log( "daily request with Alchemy: ".$dailyRequest."/ ".$config->alchemy->api->dailyLimit, false );
		
		while( $dailyRequest > $config->alchemy->api->dailyLimit){
			$this->_log( "pause Alchemy service at requests :". $dailyRequest.", limit:".$config->alchemy->api->dailyLimit, false );
			usleep( 20000000 ); // 20 sec
			$dailyRequest = Application_Model_QuotasMapper::getDailyRequest();
		}
		
		$this->_log( "daily request ok, proceed", false );
		
		// 2. load sentences
		$sentences = Application_Model_SentencesMapper::getSentences( $user, $document->id );
		$amountOfSentences = count( $sentences );
		
		$this->_log( "sentences stored into database: ". $amountOfSentences, false );
		
		if( $amountOfSentences == 0 ){
			// break chain, there isn't any sentence
			$this->_log( "no sentences saved into database, then chunk...", false );
			
			$this->_chunkIntoSentences();
			$sentences = Application_Model_SentencesMapper::getSentences( $user, $document->id );
			$amountOfSentences = count( $sentences );
			$this->_log( "extracted $amountOfSentences sentences", false );
		}
		
		// reset chunk
		$chunk = "";
	 
		// reset the value
		$chunksLength = 0;
		
		$startTime =  microtime( true );
		
		// save categories
		Application_Model_CategoriesMapper::add( $user, 'keyword' );
		Application_Model_CategoriesMapper::add( $user, 'type' );
			
		
		for( $i = 0; $i < count( $sentences ); $i++ ){
			if( strlen( $chunk ) + strlen( $sentences[ $i ]->content )  > 5000 ){
				$this->_log ( "chunk: ". strlen( $chunk ), false );	
				
				// increment chunks length
				$chunksLength += strlen( $chunk );
				
				$this->alchemyRoutine( $chunk, $config );
				
				
				// call please
				
				if( !$this->isValid() ) {
					$this->_log (  "alchemy error occurred, breaking loop, elapsed:".( microtime( true ) - $startTime ), false );
					break;
				} else{
					$this->_log (  "after alchemy, elapsed:".( microtime( true ) - $startTime ), false );
				
				}
				
				
				// pause
				usleep( mt_rand ( 2000000 , 6000000 )  );
				
				// unset chunk & json
				$chunk = "";
				$jsonResponse = null;
				
				if( isset( $_GET[ 'debug' ] ) ) break;
			}
		
			// queue sentences
			$chunk .= $sentences[ $i ]->content.". " ;
		}
		echo "error:[{$this->error}]".( $this->isValid() === true?"si":"no" );
		
		if( strlen($chunk) > 3 && $this->isValid() ) $this->alchemyRoutine( $chunk, $config );
		
		// attach relevance to the document
		foreach( $this->_alchemyEntities as $idEntity => $stats ){
			// attach to the document
			Application_Model_Rws_EntitiesDocumentsMapper::add( $user, $idEntity,  $document->id , $stats->getMedian(), $stats->getFrequency() );
		}
		
		return true;
	}
	
	// save entities
	protected	$_alchemyEntities = array();
	
	protected function alchemyRoutine( $chunk, $config ){
		if( isset( $_GET[ 'debug' ] ) ) echo "\n\n{$chunk}\n\n";
		
		// ALCHEMY
		$doc  =& $this->_target;
		$user =& $this->_distiller->user;
		
		$alchemy = new Textopoly_Alchemy( $config->alchemy->api->entities, array(
			"outputMode" => "json",
			"text" => $chunk,
			"apikey" => $config->alchemy->api->key
		));
		
		// add quota to alchemy table
		Application_Model_QuotasMapper::addQuota( 'AE', strlen($chunk), $alchemy->getResponseLength() );
		
		if( $alchemy->hasError() ){
			$this->_log(  "alchemy api error: ".$alchemy->getError(), false );
			
			if( $alchemy->getError() == "daily-transaction-limit-exceeded" ){
				// it's a special error...
				$this->_error("daily-transaction-limit-exceeded"); 
			}
			
			return;
		}
		
		// read alchemy response
		$jsonResponse = $alchemy->get();
		
		// get document language
		$language = substr( $jsonResponse->language, 0, 2 );
		
		// log number of entities found
		$this->_log(  "alchemy found ". count( $jsonResponse->entities ). " entities", false );
		
		
		echo "\n";
		
		
		foreach( $jsonResponse->entities as $entity ){
			$idEntity = Application_Model_Rws_EntitiesMapper::add(
				$user,  $entity->text,  'AL'
			);
			
			// ignore dummy 0 results...
			if( $idEntity == 0 ) continue;
			
			// co-occurrences in entities removed
			// we don't know what the relevance score is and it is different from the ngram score given
			// in keywords
			if( !isset( $this->_alchemyEntities[ $idEntity ] ) ){
				$this->_alchemyEntities[ $idEntity ] = new Anta_Distiller_Rws_Helpers_Statistics();
				$this->_alchemyEntities[ $idEntity ]->addValue( $entity->relevance, $entity->count );
			} 
			// $this->_alchemyEntities[ $idEntity ]->addValue( $entity->relevance, $entity->count );
			
			if( isset( $_GET['debug'] ) ){
				echo "    [{$idEntity}] ".$entity->text." ( ".$entity->relevance.", ".$entity->count ." )\n";
			}
			
			// create tag
			$idTag = Application_Model_TagsMapper::add( $user, $entity->type, 'type' );
			if( $idTag == 0 )  continue;
			
			// attach tags
			Application_Model_Rws_EntitiesTagsMapper::add( $user, $idEntity, $idTag );
			
		}
		
		
		echo "\n";
		
		
		// load alchemy keywords
		$this->_log(  "curl Alchemy: ". $config->alchemy->api->keywords, false );
		
		
		
		$alchemy = new Textopoly_Alchemy( $config->alchemy->api->keywords, array(
			"outputMode" => "json",
			"text" => $chunk,
			"apikey" => $config->alchemy->api->key
		));
		
		Application_Model_QuotasMapper::addQuota( 'AK', strlen($chunk), $alchemy->getResponseLength() );
		
		if( $alchemy->hasError() ){
			$this->_log(  "alchemy api error, keywords:".$alchemy->getError(), false );
			
			if( $alchemy->getError() == "daily-transaction-limit-exceeded" ){
				// it's a special error...
				$this->_error("daily-transaction-limit-exceeded"); 
			}
			
			return;
		}
		
		$jsonResponse = $alchemy->get();
		
		
		// log number of keywords found
		$this->_log(  "alchemy found ". count( $jsonResponse->keywords ). " keywords", false );
		
		echo "\n";
		
		// save entities keywords
		foreach( $jsonResponse->keywords as $entity ){
			
			$idEntity = Application_Model_Rws_EntitiesMapper::add(
				$user,  $entity->text, 'AL'
			);
			
			// ignore dummy 0 results...
			if( $idEntity == 0 ) continue;
			
			$count = preg_match_all ( "/".preg_quote( $entity->text )."/i" , $chunk, $matches );
			
			if( isset( $_GET['debug'] ) ){
				echo "    [{$idEntity}] ".$entity->text." ( ".$entity->relevance.",$count )\n";
			}
			
			// co-occurrences
			if( !isset( $this->_alchemyEntities[ $idEntity ] ) ){
				$this->_alchemyEntities[ $idEntity ] = new Anta_Distiller_Rws_Helpers_Statistics();
			} 
			$this->_alchemyEntities[ $idEntity ]->addValue( $entity->relevance, 1 );
		}
		
		echo "\n";
		
		
		
	}
		
}
?>