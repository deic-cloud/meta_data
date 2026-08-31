<?php

declare(strict_types=1);

// Predefined metadata schemas seeded by Version004 (sourced from the old
// ScienceData service 2026-08-31; see reference/metadata_schemas/). Idempotent.
return [
	[
		'name' => 'DUBLIN_CORE',
		'color' => '235789',
		'description' => 'Dublin Core Metadata Initiative (DCMI) meta-data terms, as defined here:
http://dublincore.org/documents/2012/06/14/dcmi-terms/?v=elements',
		'keys' => ['abstract', 'accessRights', 'accrualMethod', 'accrualPeriodicity', 'alternative', 'accrualPolicy', 'available', 'bibliographicCitation', 'audience', 'conformsTo', 'contributor', 'coverage', 'creator', 'date', 'created', 'dateAccepted', 'dateCopyrighted', 'dateSubmitted', 'description', 'extent', 'educationLevel', 'format', 'hasFormat', 'hasPart', 'hasVersion', 'isFormatOf', 'identifier', 'isPartOf', 'instructionalMethod', 'isReferencedBy', 'isReplacedBy', 'isRequiredBy', 'issued', 'isVersionOf', 'language', 'license', 'mediator', 'medium', 'modified', 'provenance', 'publisher', 'references', 'replaces', 'requires', 'relation', 'rights', 'rightsHolder', 'source', 'subject', 'spatial', 'temporal', 'tableOfContents', 'title', 'type', 'valid'],
	],
	[
		'name' => 'DUBLIN_CORE_ORIG',
		'color' => '6F42C1',
		'description' => 'Dublin Core meta-data element set, version 1.1, as defined here:
http://dublincore.org/documents/dces/',
		'keys' => ['Subject', 'Title', 'Description', 'Publisher', 'Contributor', 'Date', 'Type', 'Format', 'Identifier', 'Source', 'Language', 'Relation', 'Coverage', 'Rights', 'Creator'],
	],
	[
		'name' => 'CSMD',
		'color' => '8A6D3B',
		'description' => 'Elements of the Core Scientific Metadata Model (CSMD), as defined on:
http://icatproject.org/user-documentation/csmd/',
		'keys' => ['Application', 'application_job', 'application_name', 'application_version', 'Datafile', 'datafile_checksum', 'datafile_datafileCreateTime', 'datafile_datafileFormat', 'datafile_datafileModTime', 'datafile_dataset', 'datafile_description', 'datafile_destDatafile', 'datafile_doi', 'datafile_fileSize', 'DatafileFormat', 'datafileformat_datafile', 'datafileformat_description', 'datafileformat_facility', 'datafileformat_name', 'datafileformat_type', 'datafileformat_version', 'datafile_location', 'datafile_name', 'datafile_parameter', 'DatafileParameter', 'datafileparameter_datafile', 'datafile_sourceDatafile', 'Dataset', 'dataset_complete', 'dataset_datafile', 'dataset_description', 'dataset_doi', 'dataset_endDate', 'dataset_investigation', 'dataset_location', 'dataset_name', 'dataset_parameter', 'DatasetParameter', 'datasetparameter_dataset', 'dataset_sample', 'dataset_startDate', 'dataset_type', 'DatasetType', 'datasettype_dataset', 'datasettype_description', 'datasettype_facility', 'datasettype_name', 'Facility', 'FacilityCycle', 'facilitycycle_description', 'facilitycycle_endDate', 'facilitycycle_facility', 'facilitycycle_investigation', 'facilitycycle_name', 'facilitycycle_startDate', 'facility_datafileFormat', 'facility_datasetType', 'facility_daysUntilRelease', 'facility_description', 'facility_facilityCycle', 'facility_fullName', 'facility_instrument', 'facility_investigation', 'facility_investigationType', 'facility_name', 'facility_parameterType', 'facility_sampleType', 'facility_url', 'inputdatafile', 'inputdataset', 'Instrument', 'instrument_description', 'instrument_facility', 'instrument_fullName', 'instrument_instrumentScientist', 'instrument_investigation', 'instrument_name', 'instrumentscientist_instrument', 'instrument_type', 'Investigation', 'investigation_dataset', 'investigation_doi', 'investigation_endDate', 'investigation_facility', 'investigation_facilityCycle', 'investigation_instrument', 'investigation_investigationUser', 'investigation_keyword', 'investigation_name', 'investigation_parameter', 'InvestigationParameter', 'investigationparameter_investigation', 'investigation_publication', 'investigation_releaseDate', 'investigation_sample', 'investigation_shift', 'investigation_startDate', 'investigation_study', 'investigation_summary', 'investigation_title', 'investigation_type', 'InvestigationType', 'investigationtype_description', 'investigationtype_facility', 'investigationtype_investigation', 'investigationtype_name', 'InvestigationUser', 'investigationuser_investigation', 'investigationuser_role', 'investigationuser_user', 'investigation_visitId', 'Job', 'job_application', 'Keyword', 'keyword_investigation', 'keyword_name', 'outputdatafile', 'outputdataset', 'Parameter', 'parameter_dateTimeValue', 'parameter_error', 'parameter_numericValue', 'parameter_rangeBottom', 'parameter_rangeTop', 'parameter_stringValue', 'parameter_type', 'ParameterType', 'parametertype_applicableToDatafile', 'parametertype_applicableToDataset', 'parametertype_applicableToInvestigation', 'parametertype_applicableToSample', 'parametertype_description', 'parametertype_enforced', 'parametertype_facility', 'parametertype_maximumNumericValue', 'parametertype_minimumNumericValue', 'parametertype_name', 'parametertype_permissiblestringvalue', 'parametertype_units', 'parametertype_unitsFullName', 'parametertype_valueType', 'parametertype_verified', 'PermissibleStringValue', 'permissiblestringvalue_type', 'permissiblestringvalue_value', 'Publication', 'publication_doi', 'publication_fullReference', 'publication_investigation', 'publication_repository', 'publication_repositoryId', 'publication_url', 'RelatedDatafile', 'relateddatafile_destDatafile', 'relateddatafile_relation', 'relateddatafile_sourceDatafile', 'Sample', 'sample_dataset', 'sample_investigation', 'sample_name', 'sample_parameter', 'SampleParameter', 'sampleparameter_sample', 'sample_type', 'SampleType', 'sampletype_facility', 'sampletype_molecularFormula', 'sampletype_name', 'sampletype_safetyInformation', 'sampletype_sample', 'Shift', 'shift_comment', 'shift_endDate', 'shift_investigation', 'shift_startDate', 'Study', 'study_description', 'study_endDate', 'study_investigation', 'study_name', 'study_startDate', 'study_status', 'study_user', 'User', 'user_fullName', 'user_investigationUser', 'user_name', 'user_study'],
	],
	[
		'name' => 'ICAT',
		'color' => '1B456D',
		'description' => 'Elements of the ICAT schema, as defined on:
http://icatproject.org/user-documentation/icat-schema/',
		'keys' => ['Application', 'DataCollection', 'DataCollectionDatafile', 'DataCollectionDataset', 'DataCollectionParameter', 'Datafile', 'DatafileFormat', 'DatafileParameter', 'Dataset', 'DatasetParameter', 'DatasetType', 'Facility', 'FacilityCycle', 'Grouping', 'Instrument', 'InstrumentScientist', 'Investigation', 'InvestigationGroup', 'InvestigationInstrument', 'InvestigationParameter', 'InvestigationType', 'InvestigationUser', 'Job', 'Keyword', 'Log', 'ParameterType', 'PermissibleStringValue', 'PublicStep', 'Publication', 'RelatedDatafile', 'Rule', 'Sample', 'SampleParameter', 'SampleType', 'Shift', 'Study', 'StudyInvestigation', 'User', 'UserGroup'],
	],
	[
		'name' => 'AVM',
		'color' => '2D7D46',
		'description' => 'Astronomy Visualization Metadata (AVM) elements, as defined on: http://www.virtualastronomy.org/avm_metadata.php',
		'keys' => ['Creator', 'CreatorURL', 'Contact.Name', 'Contact.Email', 'Contact.Address', 'Contact.Telephone', 'Contact.City', 'Contact.StateProvince', 'Contact.PostalCode', 'Contact.Country', 'Rights', 'Title', 'Headline', 'Description', 'Subject.Category', 'Subject.Name', 'Distance', 'Distance.Notes', 'ReferenceURL', 'Credit', 'Date', 'ID', 'Type', 'Image.ProductQuality', 'Facility', 'Instrument', 'Spectral.ColorAssignment', 'Spectral.Band', 'Spectral.Bandpass', 'Spectral.CentralWavelength', 'Spectral.Notes', 'Temporal.StartTime', 'Temporal.IntegrationTime', 'DatasetID', 'Spatial.CoordinateFrame', 'Spatial.Equinox', 'Spatial.ReferenceValue', 'Spatial.ReferenceDimension', 'Spatial.ReferencePixel', 'Spatial.Scale', 'Spatial.Rotation', 'Spatial.CoordsystemProjection', 'Spatial.Quality', 'Spatial.Notes', 'Spatial.FITSheader', 'Spatial.CDMatrix', 'Publisher', 'PublisherID', 'ResourceID', 'ResourceURL', 'RelatedResources', 'MetadataDate', 'MetadataVersion', 'FL.BackgroundLevel', 'FL.BlackLevel', 'FL.ScaledPeakLevel', 'FL.PeakLevel', 'FL.WhiteLevel', 'FL.ScaledBackgroundLevel', 'FL.StretchFunction', 'PublicationID', 'ProposalID'],
	],
	[
		'name' => 'Zenodo',
		'color' => '2D7D46',
		'description' => 'Zenodo mandatory metadata attributes. Before publishing a file or dataset to Zenodo, the file or compressed archive must be tagged with this tag and the corresponding metadata filled in.

- publication_type: Only required if upload_type is',
		'keys' => ['title', 'description', 'creators', 'upload_type', 'publication_type', 'image_type', 'publication_date', 'access_right', 'access_conditions', 'embargo_date', 'license', 'communities', 'deposition_id', 'uploaded', 'bucket', 'url'],
	],
	[
		'name' => 'MediaCMS',
		'color' => '1B456D',
		'description' => 'Metadata for publishing to a MediaCMS service.',
		'keys' => ['Title', 'Description'],
	],
	[
		'name' => 'ScienceNotebooks',
		'color' => '8A6D3B',
		'description' => 'Metadata for publishing to sciencenotebooks.dk.',
		'keys' => ['Category'],
	],
	[
		'name' => 'diary',
		'color' => '6F42C1',
		'description' => 'Diary note for Notes app',
		'keys' => [],
	],
	[
		'name' => 'illustration',
		'color' => '1B456D',
		'description' => 'denotes that the file is an illustration and has an Creative Commons license (https://creativecommons.org/licenses/) or CC0 public domain waiver (https://creativecommons.org/share-your-work/public-domain/cc0) associated with it',
		'keys' => [],
	],
	[
		'name' => 'lab_notebook',
		'color' => 'C9302C',
		'description' => 'Laboratory notebook entry, i.e. note for a given day.',
		'keys' => [],
	],
	[
		'name' => 'log',
		'color' => 'C9302C',
		'description' => 'Log book entry, i.e. log for a given day.',
		'keys' => [],
	],
	[
		'name' => 'paper',
		'color' => '235789',
		'description' => 'Research paper',
		'keys' => [],
	],
	[
		'name' => 'recipe',
		'color' => '235789',
		'description' => 'Recipe note for Notes app',
		'keys' => [],
	],
	[
		'name' => 'todo',
		'color' => '8A6D3B',
		'description' => 'Todo note for Notes app',
		'keys' => [],
	],
];
